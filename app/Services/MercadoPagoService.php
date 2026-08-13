<?php

namespace App\Services;

use App\Contracts\CobravelMp;
use App\Models\LogPagamento;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente do Mercado Pago para checkout transparente.
 *
 * Atende qualquer coisa que implemente CobravelMp — hoje `Pedido` (venda de
 * vídeo) e `Assinatura` (plano do dono do evento). O serviço não sabe a
 * diferença: pergunta valor, descrição, pagador e referência.
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
    public function criarPagamentoPix(CobravelMp $cobranca): array
    {
        $payload = array_filter([
            'transaction_amount' => $cobranca->mpValor(),
            'description' => $cobranca->mpDescricao(),
            'payment_method_id' => 'pix',
            'external_reference' => $cobranca->mpReferenciaExterna(),
            'notification_url' => $this->notificationUrl() ?: null,
            'date_of_expiration' => now()->addMinutes($this->pixExpiraMinutos)->format('Y-m-d\TH:i:s.vP'),
            'payer' => $cobranca->mpPagador(),
        ], fn ($v) => $v !== null);

        $response = $this->post('/v1/payments', $payload, $cobranca->mpChaveIdempotencia('pix'));
        $data = $response->json() ?? [];

        LogPagamento::info(
            $cobranca,
            'pix.criado',
            'PIX gerado no MP',
            $this->sanitize($payload),
            $data,
        );

        if (! $response->successful() || empty($data['id'])) {
            LogPagamento::error($cobranca, 'pix.erro', 'MP não retornou payment_id', $payload, $data);
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
    public function criarPagamentoCartao(CobravelMp $cobranca, array $dados): array
    {
        $pagador = $cobranca->mpPagador();

        // O CPF do Bricks (titular do cartão) tem precedência sobre o cadastrado:
        // alguns bancos recusam quando o documento não é o do titular.
        if (isset($dados['identification'])) {
            $pagador['identification'] = [
                'type' => 'CPF',
                'number' => preg_replace('/\D/', '', $dados['identification']),
            ];
        }

        $payload = [
            'transaction_amount' => $cobranca->mpValor(),
            'description' => $cobranca->mpDescricao(),
            'external_reference' => $cobranca->mpReferenciaExterna(),
            'notification_url' => $this->notificationUrl(),
            'token' => $dados['token'],
            'installments' => (int) ($dados['installments'] ?? 1),
            'payment_method_id' => $dados['payment_method_id'] ?? null,
            'issuer_id' => $dados['issuer_id'] ?? null,
            'payer' => $pagador,
        ];
        // Remove chaves nulas — MP rejeita alguns valores null
        $payload = array_filter($payload, fn ($v) => $v !== null && $v !== '');
        $payload['payer'] = array_filter($payload['payer'], fn ($v) => $v !== null && $v !== '');

        $response = $this->post('/v1/payments', $payload, $cobranca->mpChaveIdempotencia('cartao'));
        $data = $response->json() ?? [];

        LogPagamento::info(
            $cobranca,
            'cartao.enviado',
            'Cartão enviado ao MP',
            $this->sanitize($payload),
            $data,
        );

        if (! $response->successful() || empty($data['id'])) {
            LogPagamento::error($cobranca, 'cartao.erro', 'MP não retornou payment_id', $this->sanitize($payload), $data);
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
    public function consultarPagamento(string $paymentId, ?CobravelMp $cobranca = null): array
    {
        $response = $this->get("/v1/payments/{$paymentId}");
        $data = $response->json() ?? [];

        if (! $response->successful()) {
            LogPagamento::warning($cobranca, 'status.consulta_falhou', "MP {$response->status()}", null, $data);
            throw new RuntimeException('Falha ao consultar status no Mercado Pago.');
        }

        return [
            'status' => $data['status'] ?? 'pending',
            'status_detail' => $data['status_detail'] ?? null,
            'raw' => $data,
        ];
    }

    /**
     * URL que o MP chama quando o status do payment muda. Enviada por request
     * (não é webhook global cadastrado no painel MP) — permite ambientes
     * diferentes (staging/prod) sem conflito. Deve ser HTTPS pública.
     *
     * Nossa validação de segurança: SEMPRE consultamos o MP pelo payment_id
     * com nosso access_token pra confirmar o status real. O body da notificação
     * não é confiado — atacante que POSTe payment_id forjado só faz nosso
     * backend consultar o MP e receber 404 ou payment de outra conta.
     * Rota é rate-limited pra mitigar spam.
     */
    private function notificationUrl(): string
    {
        // MP EXIGE HTTPS pra notification_url — se APP_URL for HTTP (dev local),
        // omite (polling client-side cobre). Se rota não existir (deploy velho),
        // idem — não faz sentido derrubar o payment por causa disso.
        try {
            $url = route('publico.pagamento.notificacao');
            return str_starts_with($url, 'https://') ? $url : '';
        } catch (\Throwable) {
            return '';
        }
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
