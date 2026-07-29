<?php

namespace App\Services;

use App\Models\LogPagamento;
use App\Models\Pedido;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cliente do Mercado Pago para checkout transparente.
 *
 * NÃO usa webhook — o polling client-side é o mecanismo de sync
 * (ver PagamentoController::status). O objetivo é manter a integração
 * simples e sem dependência de URL pública para receber callbacks.
 *
 * Toda chamada ao MP é registrada em LogPagamento (payload + response +
 * gateway_status). Falhas de rede/timeout também são logadas — nunca
 * silenciam.
 */
class MercadoPagoService
{
    private const BASE_URL = 'https://api.mercadopago.com';
    private const TIMEOUT_SECONDS = 15;

    private string $accessToken;
    private int $pixExpiraMinutos;

    public function __construct()
    {
        $this->accessToken = (string) config('services.mercadopago.access_token');
        $this->pixExpiraMinutos = (int) config('services.mercadopago.pix_expira_minutos', 30);

        if ($this->accessToken === '') {
            throw new RuntimeException(
                'MERCADOPAGO_ACCESS_TOKEN não configurado no .env — checkout de pagamento indisponível.'
            );
        }
    }

    /**
     * Cria um pagamento PIX. Retorna o QR code (imagem base64 + código
     * copia-e-cola) que o frontend renderiza pro comprador.
     */
    public function criarPagamentoPix(Pedido $pedido): array
    {
        $payload = [
            'transaction_amount' => (float) $pedido->total,
            'description' => $this->descricaoPedido($pedido),
            'payment_method_id' => 'pix',
            'external_reference' => (string) $pedido->id,
            'date_of_expiration' => now()->addMinutes($this->pixExpiraMinutos)->format('Y-m-d\TH:i:s.vP'),
            'payer' => [
                'email' => $pedido->comprador_email,
                'first_name' => Str::of($pedido->comprador_nome)->before(' ')->limit(60, ''),
                'last_name' => Str::of($pedido->comprador_nome)->after(' ')->limit(60, '') ?: '.',
            ],
        ];

        $response = $this->post('/v1/payments', $payload, $this->idempotencyKey($pedido, 'pix'));
        $data = $response->json() ?? [];

        LogPagamento::info(
            $pedido,
            'pix.criado',
            'PIX gerado no MP',
            $this->sanitize($payload),
            $data,
        );

        if (! $response->successful() || empty($data['id'])) {
            LogPagamento::error($pedido, 'pix.erro', 'MP não retornou payment_id', $payload, $data);
            throw new RuntimeException('Falha ao gerar PIX no Mercado Pago.');
        }

        // QR code fica em point_of_interaction.transaction_data (padrão MP para PIX).
        $tx = $data['point_of_interaction']['transaction_data'] ?? [];

        return [
            'payment_id' => (string) $data['id'],
            'status' => $data['status'] ?? 'pending',
            'qr_code' => $tx['qr_code'] ?? null,               // copia-e-cola
            'qr_code_base64' => $tx['qr_code_base64'] ?? null, // imagem PNG base64
            'ticket_url' => $tx['ticket_url'] ?? null,         // fallback link
            'expires_at' => $data['date_of_expiration'] ?? null,
            'raw' => $data,
        ];
    }

    /**
     * Processa um pagamento com CARTÃO usando o token gerado pelo Bricks
     * no frontend. O CVV/número nunca chega ao backend — o token é one-time-use.
     */
    public function criarPagamentoCartao(Pedido $pedido, array $dados): array
    {
        $payload = [
            'transaction_amount' => (float) $pedido->total,
            'description' => $this->descricaoPedido($pedido),
            'external_reference' => (string) $pedido->id,
            'token' => $dados['token'],
            'installments' => (int) ($dados['installments'] ?? 1),
            'payment_method_id' => $dados['payment_method_id'] ?? null,
            'issuer_id' => $dados['issuer_id'] ?? null,
            'payer' => [
                'email' => $pedido->comprador_email,
                // MP exige identificação do titular do cartão pra alguns bancos.
                // Se o Bricks capturou o CPF, envia; senão MP infere do token.
                'identification' => isset($dados['identification'])
                    ? ['type' => 'CPF', 'number' => preg_replace('/\D/', '', $dados['identification'])]
                    : null,
            ],
        ];
        // Remove chaves nulas — MP rejeita alguns valores null
        $payload = array_filter($payload, fn ($v) => $v !== null && $v !== '');
        $payload['payer'] = array_filter($payload['payer'], fn ($v) => $v !== null && $v !== '');

        $response = $this->post('/v1/payments', $payload, $this->idempotencyKey($pedido, 'cartao'));
        $data = $response->json() ?? [];

        LogPagamento::info(
            $pedido,
            'cartao.enviado',
            'Cartão enviado ao MP',
            $this->sanitize($payload),
            $data,
        );

        if (! $response->successful() || empty($data['id'])) {
            LogPagamento::error($pedido, 'cartao.erro', 'MP não retornou payment_id', $this->sanitize($payload), $data);
            throw new RuntimeException($data['message'] ?? 'Falha ao processar cartão no Mercado Pago.');
        }

        return [
            'payment_id' => (string) $data['id'],
            'status' => $data['status'] ?? 'pending',
            'status_detail' => $data['status_detail'] ?? null,
            'raw' => $data,
        ];
    }

    /**
     * Consulta o estado atual de um payment (usado no polling).
     */
    public function consultarPagamento(string $paymentId, ?Pedido $pedido = null): array
    {
        $response = $this->get("/v1/payments/{$paymentId}");
        $data = $response->json() ?? [];

        if (! $response->successful()) {
            LogPagamento::warning($pedido, 'status.consulta_falhou', "MP {$response->status()}", null, $data);
            throw new RuntimeException('Falha ao consultar status no Mercado Pago.');
        }

        return [
            'status' => $data['status'] ?? 'pending',
            'status_detail' => $data['status_detail'] ?? null,
            'raw' => $data,
        ];
    }

    private function descricaoPedido(Pedido $pedido): string
    {
        $qtd = $pedido->itens()->count();
        return "Pedido #{$pedido->id} — {$qtd} " . ($qtd === 1 ? 'item' : 'itens');
    }

    /**
     * Chave de idempotência — MP dedupe requests com mesma chave nas últimas 24h.
     * Fundamental pra evitar cobrar 2x se a request falhar por timeout e o front
     * retentar (comportamento comum em rede móvel instável).
     */
    private function idempotencyKey(Pedido $pedido, string $tipo): string
    {
        return "pedido-{$pedido->id}-{$tipo}-" . $pedido->created_at?->format('YmdHis');
    }

    private function post(string $path, array $payload, string $idempotencyKey): Response
    {
        return $this->httpClient()
            ->withHeaders(['X-Idempotency-Key' => $idempotencyKey])
            ->post(self::BASE_URL . $path, $payload);
    }

    private function get(string $path): Response
    {
        return $this->httpClient()->get(self::BASE_URL . $path);
    }

    private function httpClient()
    {
        return Http::withToken($this->accessToken)
            ->acceptJson()
            ->asJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->retry(2, 500, fn ($ex) => $ex instanceof \Illuminate\Http\Client\ConnectionException);
    }

    /**
     * Redação de dados sensíveis antes de gravar em log — token de cartão
     * é one-time-use, mas ainda evita expô-lo por precaução.
     */
    private function sanitize(array $payload): array
    {
        foreach (['token', 'card_token', 'cvv', 'security_code'] as $k) {
            if (isset($payload[$k])) $payload[$k] = '[REDACTED]';
        }
        return $payload;
    }
}
