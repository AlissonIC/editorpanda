<?php

namespace App\Services;

use App\Jobs\MesclarVideosJob;
use App\Models\Configuracao;
use App\Models\LogProcessamento;
use App\Models\Pedido;
use App\Models\Video;
use App\Models\VideoMerge;
use Illuminate\Support\Facades\DB;

/**
 * Materializa o "quero tudo num arquivo só" marcado pelo comprador no checkout.
 *
 * Chamado de três lugares que podem confirmar o mesmo pedido (checkout de álbum
 * gratuito, polling do MP e notificação do MP), então TEM que ser idempotente:
 * a trava é um único merge automático por pedido, garantida por lock na linha
 * do pedido + checagem de existência.
 */
class PedidoMergeService
{
    /**
     * Cria e enfileira a mescla do pedido, se ele pediu e ainda não tem uma.
     * Devolve null quando não há nada a fazer (não é erro).
     */
    public function criarSeSolicitado(Pedido $pedido): ?VideoMerge
    {
        if (! $pedido->mesclar_solicitado || $pedido->status !== Pedido::STATUS_PAGO) {
            return null;
        }

        // Álbum de edição manual não tem entrega automática — mesclar não faz sentido.
        if ($pedido->album?->ehEdicaoManual()) {
            return null;
        }

        $merge = DB::transaction(function () use ($pedido) {
            // Lock no pedido serializa chamadas concorrentes (polling do front e
            // notificação do MP chegam quase juntos) — sem isso saem 2 merges.
            $lock = Pedido::whereKey($pedido->id)->lockForUpdate()->first();
            if (! $lock) {
                return null;
            }

            if (VideoMerge::where('pedido_id', $lock->id)->whereNotNull('comprador_id')->exists()) {
                return null;
            }

            // Só entra no concat o que está entregável. Se o pedido tem 1 vídeo
            // concluído e o resto travado, não há o que mesclar.
            $videoIds = $lock->itens()->pluck('video_id');
            $prontos = Video::whereIn('id', $videoIds)
                ->where('status', Video::STATUS_CONCLUIDO)
                ->whereNotNull('arquivo_processado_path')
                ->orderBy('id')
                ->pluck('id')
                ->all();

            if (count($prontos) < 2) {
                LogProcessamento::info('merge.pedido.ignorado', 'Pedido pediu mescla mas não tem 2+ vídeos prontos', [
                    'pedido_id' => $lock->id,
                    'prontos' => count($prontos),
                ]);
                return null;
            }

            return VideoMerge::create([
                'comprador_id' => $lock->comprador_id,
                'pedido_id' => $lock->id,
                'user_id' => null,
                'video_ids' => $prontos,
                'status' => VideoMerge::STATUS_PENDENTE,
                'disk' => Configuracao::storageDisk(),
            ]);
        });

        if (! $merge) {
            return null;
        }

        // Dispatch fora da transação: com QUEUE_CONNECTION=database o worker
        // pode pegar o job antes do commit e não achar a linha do merge.
        MesclarVideosJob::dispatch($merge->id);

        LogProcessamento::info('merge.pedido.enfileirado', 'Mescla automática do pedido enfileirada', [
            'pedido_id' => $pedido->id,
            'merge_id' => $merge->id,
            'videos' => count($merge->video_ids),
        ]);

        return $merge;
    }
}
