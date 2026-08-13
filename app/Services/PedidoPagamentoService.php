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

            // O valor creditado fica gravado no log, não só a fórmula: se o
            // admin estornar o pedido depois de o plano ter mudado de taxa,
            // recalcular devolveria um número diferente do que entrou.
            LogPagamento::info($lock, 'pedido.pago', "Total R$ {$lock->total} liberado", [
                'credito_vendedor' => $credito,
                'taxa_aplicada' => $taxa,
            ]);

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

    /** Cancela o pedido. Se estava pago, devolve o crédito do vendedor. */
    public function cancelar(Pedido $pedido, ?string $motivo = null): void
    {
        $this->mudarPara($pedido, Pedido::STATUS_CANCELADO, $motivo ?? 'Pedido cancelado');
    }

    /**
     * Volta o pedido pra 'pendente' — usado quando o admin liberou por engano
     * ou o comprador vai tentar pagar de novo. Se estava pago, também estorna.
     */
    public function reabrir(Pedido $pedido, ?string $motivo = null): void
    {
        $this->mudarPara($pedido, Pedido::STATUS_PENDENTE, $motivo ?? 'Pedido reaberto');
    }

    /**
     * Tira o pedido de 'pago' e desfaz o crédito do vendedor.
     *
     * O valor devolvido é o que REALMENTE entrou, lido do log da liberação —
     * não recalculado. Se a taxa do plano mudou desde então, recalcular
     * devolveria um número diferente do que foi creditado, e a diferença
     * ficaria pendurada no saldo pra sempre.
     *
     * O saldo pode ficar NEGATIVO se o vendedor já sacou. É proposital: o
     * negativo é a representação honesta da dívida e o fluxo de saque já
     * recusa retirada acima do saldo, então ele não saca de novo até quitar.
     * Bloquear a correção deixaria o pedido errado para sempre — pior.
     */
    private function mudarPara(Pedido $pedido, string $novoStatus, string $motivo): void
    {
        DB::transaction(function () use ($pedido, $novoStatus, $motivo) {
            $lock = Pedido::whereKey($pedido->id)->lockForUpdate()->first();
            if ($lock->status === $novoStatus) {
                return;
            }

            $estornou = null;
            if ($lock->status === Pedido::STATUS_PAGO) {
                $estornou = $this->estornarCredito($lock);
            }

            // Sai de 'pago' → a data de pagamento deixa de valer. Este método
            // só recebe 'cancelado' e 'pendente'; virar pago é marcarComoPago.
            $lock->update(['status' => $novoStatus, 'pago_em' => null]);

            $sufixo = $estornou !== null
                ? ' — R$ ' . number_format($estornou, 2, ',', '.') . ' estornado do saldo do vendedor'
                : '';

            LogPagamento::warning($lock, 'status.manual', $motivo . $sufixo);
        });
    }

    /** @return float Valor debitado do vendedor. */
    private function estornarCredito(Pedido $pedido): float
    {
        $vendedor = User::whereKey($pedido->user_id)->lockForUpdate()->with('plano')->first();
        if (! $vendedor) {
            return 0.0;
        }

        $log = LogPagamento::where('pedido_id', $pedido->id)
            ->where('evento', 'pedido.pago')
            ->orderByDesc('id')
            ->first();

        $credito = $log?->payload['credito_vendedor'] ?? null;

        // Pedidos liberados antes de o log guardar o valor caem no recálculo.
        if ($credito === null) {
            $taxa = (float) ($vendedor->plano?->taxa_por_venda ?? 0);
            $credito = round((float) $pedido->total * (1 - $taxa / 100), 2);
        }

        $creditoCents = (int) round((float) $credito * 100);
        if ($creditoCents > 0) {
            DB::table('users')->where('id', $vendedor->id)->update([
                'saldo_disponivel' => DB::raw("saldo_disponivel - ({$creditoCents} / 100)"),
            ]);
        }

        return (float) $credito;
    }
}
