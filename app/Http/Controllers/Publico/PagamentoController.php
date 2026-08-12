<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Models\LogPagamento;
use App\Models\Pedido;
use App\Models\User;
use App\Notifications\CompraFinalizadaNotification;
use App\Services\MercadoPagoService;
use App\Services\PedidoMergeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Endpoints do fluxo de pagamento (MP transparente).
 *
 * Sem webhook — o cliente polla /status até status final. O ciclo é:
 *   1. front chama /pagamento/pix ou /pagamento/cartao
 *   2. backend cria payment no MP e devolve status inicial + dados PIX (se for)
 *   3. front rendeza QR ou aguarda 3DS/rejeição e polla /status a cada 3s
 *   4. status vira "approved" (ou "rejected"/"cancelled") → front redireciona
 *
 * Todo request/response ao MP é logado em LogPagamento (auditoria + debug).
 */
class PagamentoController extends Controller
{
    public function __construct(private MercadoPagoService $mp)
    {
    }

    /**
     * Gera o PIX no MP e persiste o QR code no pedido pra exibir no front.
     * Idempotente: chamar de novo com pedido já com PIX ativo devolve o mesmo.
     */
    public function criarPix(Pedido $pedido): JsonResponse
    {
        $this->autorizarPedido($pedido);

        // Se PIX já foi criado e não expirou, devolve o mesmo — evita gerar
        // 2 payments diferentes se o comprador clicar 2x.
        if ($pedido->payment_method === 'pix' && $pedido->gateway_id
            && $pedido->pix_expires_at && $pedido->pix_expires_at->isFuture()) {
            return response()->json($this->responsePix($pedido));
        }

        try {
            $resultado = $this->mp->criarPagamentoPix($pedido);
        } catch (\Throwable $e) {
            LogPagamento::error($pedido, 'pix.exception', $e->getMessage());
            return response()->json(['message' => 'Não foi possível gerar o PIX. Tente novamente.'], 502);
        }

        $pedido->update([
            'payment_method' => 'pix',
            'gateway_id' => $resultado['payment_id'],
            'gateway_status' => $resultado['status'],
            'pix_qr_code' => $resultado['qr_code'],
            'pix_qr_code_base64' => $resultado['qr_code_base64'],
            'pix_expires_at' => $resultado['expires_at'] ? \Carbon\Carbon::parse($resultado['expires_at']) : null,
            'gateway_metadata' => $resultado['raw'],
        ]);

        return response()->json($this->responsePix($pedido));
    }

    /**
     * Processa cartão a partir do token gerado pelo Bricks no front.
     * O token é one-time-use; o cartão em si nunca passa pelo backend.
     */
    public function pagarCartao(Pedido $pedido, Request $request): JsonResponse
    {
        $this->autorizarPedido($pedido);

        $dados = $request->validate([
            'token' => ['required', 'string'],
            'installments' => ['required', 'integer', 'min:1', 'max:12'],
            'payment_method_id' => ['nullable', 'string', 'max:60'],
            'issuer_id' => ['nullable', 'string', 'max:60'],
            'identification' => ['nullable', 'string', 'max:20'],
        ]);

        try {
            $resultado = $this->mp->criarPagamentoCartao($pedido, $dados);
        } catch (\Throwable $e) {
            LogPagamento::error($pedido, 'cartao.exception', $e->getMessage());
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $pedido->update([
            'payment_method' => 'credit_card',
            'gateway_id' => $resultado['payment_id'],
            'gateway_status' => $resultado['status'],
            'gateway_metadata' => $resultado['raw'],
        ]);

        // MP pode aprovar SÍNCRONO (status='approved') — dá pra atalhar aqui
        // sem esperar o polling, já entrega o redirect na resposta.
        if ($resultado['status'] === 'approved') {
            $this->marcarComoPago($pedido, $resultado['raw']);
        }

        return response()->json([
            'status' => $resultado['status'],
            'status_detail' => $resultado['status_detail'],
            'redirect' => $pedido->status === Pedido::STATUS_PAGO
                ? $this->urlConfirmacaoAssinada($pedido)
                : null,
        ]);
    }

    /**
     * Polling: consulta status no MP, atualiza local, devolve pro front.
     * Front chama a cada 3-5s até status ser final (approved/rejected/cancelled)
     * e usa a `redirect` (assinada) pra ir pra tela de confirmação.
     */
    public function status(Pedido $pedido): JsonResponse
    {
        $this->autorizarPedido($pedido);

        // Pedido gratuito ou já pago não precisa consultar MP — devolve o estado local.
        if ($pedido->status === Pedido::STATUS_PAGO) {
            return response()->json([
                'status' => 'approved',
                'pedido_status' => $pedido->status,
                'redirect' => $this->urlConfirmacaoAssinada($pedido),
            ]);
        }

        // Sem gateway_id ainda (ex: front polla antes de criar PIX) — não é erro
        if (! $pedido->gateway_id) {
            return response()->json([
                'status' => 'pending',
                'pedido_status' => $pedido->status,
                'redirect' => null,
            ]);
        }

        try {
            $consulta = $this->mp->consultarPagamento($pedido->gateway_id, $pedido);
        } catch (\Throwable $e) {
            // Não escala pro front — polling pode ter falha pontual de rede.
            return response()->json([
                'status' => $pedido->gateway_status ?? 'pending',
                'pedido_status' => $pedido->status,
                'redirect' => null,
                'transient_error' => true,
            ]);
        }

        // Log só quando o status MUDA — polling repetido não polui os logs.
        if ($consulta['status'] !== $pedido->gateway_status) {
            LogPagamento::info(
                $pedido,
                'status.mudou',
                "MP: {$pedido->gateway_status} → {$consulta['status']}",
                null,
                $consulta['raw'],
            );
            $pedido->update([
                'gateway_status' => $consulta['status'],
                'gateway_metadata' => $consulta['raw'],
            ]);
        }

        // Transições de status → pedido interno
        if ($consulta['status'] === 'approved' && $pedido->status !== Pedido::STATUS_PAGO) {
            $this->marcarComoPago($pedido, $consulta['raw']);
        } elseif (in_array($consulta['status'], ['cancelled', 'rejected'], true)
                  && $pedido->status !== Pedido::STATUS_CANCELADO) {
            $pedido->update(['status' => Pedido::STATUS_CANCELADO]);
            LogPagamento::warning($pedido, 'pedido.cancelado', "MP status: {$consulta['status']}");
        }

        return response()->json([
            'status' => $consulta['status'],
            'status_detail' => $consulta['status_detail'],
            'pedido_status' => $pedido->fresh()->status,
            'redirect' => $pedido->fresh()->status === Pedido::STATUS_PAGO
                ? $this->urlConfirmacaoAssinada($pedido)
                : null,
        ]);
    }

    /**
     * URL de retorno — MP não redireciona pra cá (não usamos back_urls),
     * mas expomos como endpoint amigável caso o comprador cole o URL
     * salvo no browser. Simplesmente redireciona pra confirmação assinada.
     */
    public function retorno(Pedido $pedido)
    {
        $this->autorizarPedido($pedido);

        if ($pedido->status === Pedido::STATUS_PAGO) {
            return redirect($this->urlConfirmacaoAssinada($pedido));
        }
        return redirect()->route('publico.album.show', $pedido->album->slug ?? '');
    }

    /**
     * Endpoint chamado pelo MP quando o status de um payment muda.
     * Enviado via `notification_url` embutido em cada criação de payment
     * (não é webhook global). Sem HMAC — validamos re-consultando o payment
     * no MP com nosso access_token, então body forjado não engana.
     *
     * MP envia:
     *   - POST body: { action, api_version, data: { id }, type, live_mode, ... }
     *   - Query string: ?data.id=<payment_id>&type=payment
     *   - Pode enviar type=payment, type=merchant_order — ignoramos merchant_order
     *
     * Idempotência: MP costuma reenviar a mesma notificação várias vezes até
     * receber 2xx. Nosso handler é idempotente (marcarComoPago checa se já foi
     * pago dentro de transaction+lock, não credita saldo em duplicata).
     */
    public function notificacao(Request $request): JsonResponse
    {
        // MP manda o ID em vários formatos: body.data.id, body.id, query.data_id, query.id.
        // Tentamos todos — variações rolam entre versões da API e tipos de notification.
        $tipo = $request->input('type') ?: $request->query('type');
        $paymentId = $request->input('data.id')
            ?: $request->input('id')
            ?: $request->query('data_id')
            ?: $request->query('id');

        // Registra TUDO que chega — inclusive lixo/spam — pra ajudar em debug.
        // Retenção de logs_pagamento cuida do volume.
        LogPagamento::info(null, 'notificacao.recebida', "type={$tipo} id={$paymentId}", [
            'query' => $request->query(),
            'body' => $request->all(),
        ]);

        // Só processamos notificações de payment. Ignora merchant_order/subscriptions/etc.
        if ($tipo !== 'payment' || ! $paymentId) {
            return response()->json(['ignored' => true]);
        }

        // Consulta o MP com nosso token — se payment_id for forjado, retorna erro
        // ou payment de outra conta (que não bate com pedido nosso). Nada acontece.
        try {
            $consulta = $this->mp->consultarPagamento((string) $paymentId);
        } catch (\Throwable $e) {
            LogPagamento::error(null, 'notificacao.consulta_falhou', $e->getMessage(), [
                'payment_id' => $paymentId,
            ]);
            // Devolve 200 mesmo assim — MP tenta reenviar em erro 5xx, mas se o
            // problema é payment inexistente/forjado, retry infinito é lixo.
            return response()->json(['ok' => true]);
        }

        $raw = $consulta['raw'];
        $externalRef = $raw['external_reference'] ?? null;

        // external_reference = pedido->id (setado na criação). Sem ele, não
        // conseguimos amarrar — provavelmente é payment de outra conta.
        $pedido = $externalRef ? Pedido::find((int) $externalRef) : null;
        if (! $pedido) {
            LogPagamento::warning(null, 'notificacao.pedido_nao_encontrado', "external_ref={$externalRef}", [
                'payment_id' => $paymentId,
            ]);
            return response()->json(['ok' => true]);
        }

        // Sanity: o gateway_id do MP tem que bater com o que gravamos ao criar
        // — se diferente, alguém tá tentando amarrar payment estranho ao nosso pedido.
        if ($pedido->gateway_id && $pedido->gateway_id !== (string) $paymentId) {
            LogPagamento::warning($pedido, 'notificacao.gateway_id_divergente',
                "esperado={$pedido->gateway_id} recebido={$paymentId}");
            return response()->json(['ok' => true]);
        }

        // Log só se status mudou — evita poluir com repetições idênticas
        if ($consulta['status'] !== $pedido->gateway_status) {
            LogPagamento::info($pedido, 'notificacao.status_mudou',
                "{$pedido->gateway_status} → {$consulta['status']}", null, $raw);
            $pedido->update([
                'gateway_status' => $consulta['status'],
                'gateway_metadata' => $raw,
            ]);
        }

        // Transições finais (idempotentes — marcarComoPago faz lock e check)
        if ($consulta['status'] === 'approved' && $pedido->status !== Pedido::STATUS_PAGO) {
            $this->marcarComoPago($pedido, $raw);
        } elseif (in_array($consulta['status'], ['cancelled', 'rejected'], true)
                  && $pedido->status !== Pedido::STATUS_CANCELADO) {
            $pedido->update(['status' => Pedido::STATUS_CANCELADO]);
            LogPagamento::warning($pedido, 'notificacao.pedido_cancelado', "MP: {$consulta['status']}");
        }

        return response()->json(['ok' => true]);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function responsePix(Pedido $pedido): array
    {
        return [
            'status' => $pedido->gateway_status,
            'qr_code' => $pedido->pix_qr_code,
            'qr_code_base64' => $pedido->pix_qr_code_base64,
            'expires_at' => $pedido->pix_expires_at?->toIso8601String(),
        ];
    }

    /**
     * Assinada (30 min) — impede que copiar+compartilhar exponha o pedido.
     */
    private function urlConfirmacaoAssinada(Pedido $pedido): string
    {
        return URL::temporarySignedRoute(
            'publico.checkout.confirmacao',
            now()->addMinutes(30),
            ['pedido' => $pedido->id],
        );
    }

    /**
     * Passa o pedido pra 'pago', credita vendedor e dispara notificação.
     * Idempotente (checa se já foi pago pra não creditar 2x).
     */
    private function marcarComoPago(Pedido $pedido, array $rawMpResponse): void
    {
        DB::transaction(function () use ($pedido, $rawMpResponse) {
            $lock = Pedido::whereKey($pedido->id)->lockForUpdate()->first();
            if ($lock->status === Pedido::STATUS_PAGO) return; // já processado

            $lock->update([
                'status' => Pedido::STATUS_PAGO,
                'pago_em' => now(),
                'gateway_status' => 'approved',
                'gateway_metadata' => $rawMpResponse,
            ]);

            // Credita saldo do vendedor descontando taxa do plano.
            $vendedor = User::whereKey($lock->user_id)->lockForUpdate()->with('plano')->first();
            $taxa = (float) ($vendedor?->plano?->taxa_por_venda ?? 0);
            $credito = round((float) $lock->total * (1 - $taxa / 100), 2);
            $creditoCents = (int) round($credito * 100);
            if ($vendedor && $creditoCents > 0) {
                DB::table('users')->where('id', $vendedor->id)->update([
                    'saldo_disponivel' => DB::raw("saldo_disponivel + ({$creditoCents} / 100)"),
                ]);
            }

            LogPagamento::info($lock, 'pedido.pago', "Total R$ {$lock->total} liberado");
        });

        // Fora da transaction — falhar aqui não pode reverter o pagamento.
        $pedido->refresh();

        // Mescla opcional pedida no checkout. Idempotente: polling e notificação
        // do MP podem cair aqui quase juntos.
        try {
            app(PedidoMergeService::class)->criarSeSolicitado($pedido);
        } catch (\Throwable $e) {
            \Log::warning('Falha ao enfileirar mescla do pedido', [
                'pedido' => $pedido->id, 'erro' => $e->getMessage(),
            ]);
        }

        try {
            [$tokenPlano] = \App\Models\AcessoToken::gerarPara(
                $pedido->comprador_email,
                request()->ip(),
                request()->userAgent(),
            );
            $urlAcesso = route('publico.acesso.validar', ['token' => $tokenPlano]);
            $pedido->comprador?->notify(new CompraFinalizadaNotification($pedido, $urlAcesso));
        } catch (\Throwable $e) {
            \Log::warning('Falha ao enviar email pós-pagamento', [
                'pedido' => $pedido->id, 'erro' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Todos os endpoints são públicos (não exigem login), mas o pedido
     * PRECISA estar num estado que faça sentido interagir. Bloqueia
     * pedido de outro álbum, cancelado, etc.
     */
    private function autorizarPedido(Pedido $pedido): void
    {
        abort_if($pedido->status === Pedido::STATUS_CANCELADO, 410, 'Pedido cancelado.');
        // Não vazamos dados de pedido sem checar disponibilidade do álbum —
        // se admin marcou álbum como indisponível, corta aqui.
        $album = $pedido->album()->with('evento')->first();
        abort_unless($album && $album->status === 'publicado', 404);
        abort_unless($album->evento?->status === 'ativo', 404);
    }
}
