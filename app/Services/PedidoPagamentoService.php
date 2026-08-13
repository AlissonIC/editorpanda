<?php

namespace App\Services;

use App\Models\AcessoToken;
use App\Models\LogPagamento;
use App\Models\Pedido;
use App\Models\User;
use App\Notifications\CompraFinalizadaNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Transições de status do pedido — e tudo que elas disparam.
 *
 * Vive num serviço porque agora há duas portas de entrada: o gateway (polling
 * e notificação do Mercado Pago) e a mão do admin, na tela do pedido. As duas
 * precisam creditar o vendedor, liberar o acesso e avisar o comprador
 * exatamente igual — duplicar isso seria duplicar dinheiro.
 */
class PedidoPagamentoService
{
    /**
     * Passa o pedido pra 'pago', credita o vendedor e libera o acesso.
     *
     * Idempotente: confere sob lock se já foi pago e sai sem creditar de novo.
     * Isso importa porque polling e notificação do MP costumam chegar quase
     * juntos, e o admin pode clicar em cima de uma confirmação em andamento.
     *
     * @param  array  $rawMp  Resposta do gateway, quando veio de lá.
     * @param  string|null  $motivo  Preenchido só na troca manual — vira log.
     */
    public function marcarComoPago(Pedido $pedido, array $rawMp = [], ?string $motivo = null): void
    {
        $creditou = DB::transaction(function () use ($pedido, $rawMp) {
            $lock = Pedido::whereKey($pedido->id)->lockForUpdate()->first();
            if ($lock->status === Pedido::STATUS_PAGO) {
                return false; // já processado
            }

            $lock->update(array_filter([
                'status' => Pedido::STATUS_PAGO,
                'pago_em' => now(),
                'gateway_status' => 'approved',
                // Numa liberação manual não há resposta de gateway — preservar
                // a última do MP vale mais que sobrescrever com array vazio.
                'gateway_metadata' => $rawMp ?: null,
            ], fn ($v) => $v !== null));

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

            return true;
        });

        if (! $creditou) {
            return;
        }

        if ($motivo !== null) {
            LogPagamento::warning($pedido, 'status.manual', $motivo);
        }

        // Fora da transaction — falhar aqui não pode reverter o pagamento.
        $pedido->refresh();

        // Mescla opcional pedida no checkout. Idempotente: polling e notificação
        // do MP podem cair aqui quase juntos.
        try {
            app(PedidoMergeService::class)->criarSeSolicitado($pedido);
        } catch (\Throwable $e) {
            Log::warning('Falha ao enfileirar mescla do pedido', [
                'pedido' => $pedido->id, 'erro' => $e->getMessage(),
            ]);
        }

        try {
            [$tokenPlano] = AcessoToken::gerarPara(
                $pedido->comprador_email,
                request()->ip(),
                request()->userAgent(),
            );
            $urlAcesso = route('publico.acesso.validar', ['token' => $tokenPlano]);
            $pedido->comprador?->notify(new CompraFinalizadaNotification($pedido, $urlAcesso));
        } catch (\Throwable $e) {
            Log::warning('Falha ao enviar email pós-pagamento', [
                'pedido' => $pedido->id, 'erro' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cancela o pedido.
     *
     * NÃO estorna saldo do vendedor. Cancelar um pedido que já foi pago é caso
     * de estorno no gateway, com dinheiro andando de verdade — coisa que esta
     * tela não faz. Por isso a tela só oferece cancelar quando o pedido ainda
     * não foi pago (ver PedidosController::atualizarStatus).
     */
    public function cancelar(Pedido $pedido, ?string $motivo = null): void
    {
        DB::transaction(function () use ($pedido, $motivo) {
            $lock = Pedido::whereKey($pedido->id)->lockForUpdate()->first();
            if ($lock->status === Pedido::STATUS_CANCELADO) {
                return;
            }

            $lock->update(['status' => Pedido::STATUS_CANCELADO]);
            LogPagamento::warning($lock, $motivo !== null ? 'status.manual' : 'pedido.cancelado',
                $motivo ?? 'Pedido cancelado');
        });
    }

    /**
     * Volta o pedido pra 'pendente' — usado quando o admin liberou por engano
     * ou o comprador vai tentar pagar de novo.
     *
     * Só é permitido a partir de 'cancelado' (ver PedidosController): sair de
     * 'pago' exigiria debitar o vendedor, que pode já ter sacado o valor.
     */
    public function reabrir(Pedido $pedido, ?string $motivo = null): void
    {
        DB::transaction(function () use ($pedido, $motivo) {
            $lock = Pedido::whereKey($pedido->id)->lockForUpdate()->first();
            if ($lock->status === Pedido::STATUS_PENDENTE) {
                return;
            }

            $lock->update(['status' => Pedido::STATUS_PENDENTE]);
            LogPagamento::info($lock, 'status.manual', $motivo ?? 'Pedido reaberto');
        });
    }
}
