<?php

namespace App\Jobs;

use App\Models\LogProcessamento;
use App\Models\VideoMerge;
use App\Services\VideoMerger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Job de mescla de vídeos — concat FFmpeg.
 *
 * Rápido (sem re-encode) na maioria dos casos porque todos os vídeos passaram
 * pelo mesmo pipeline. Fallback com re-encode se algum diferir.
 */
class MesclarVideosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1920;

    public function __construct(public int $mergeId) {}

    public function handle(VideoMerger $merger): void
    {
        $merge = VideoMerge::find($this->mergeId);
        if (! $merge) {
            LogProcessamento::warning('merge.nao_encontrado', 'VideoMerge não existe', ['merge_id' => $this->mergeId]);
            return;
        }

        if ($merge->status !== VideoMerge::STATUS_PENDENTE) {
            LogProcessamento::info('merge.ignorado', "Merge não pendente ({$merge->status})", ['merge_id' => $merge->id]);
            return;
        }

        $merge->update(['status' => VideoMerge::STATUS_PROCESSANDO, 'iniciado_em' => now(), 'erro_msg' => null]);

        LogProcessamento::info('merge.processando', 'Início do merge', [
            'merge_id' => $merge->id,
            'user_id' => $merge->user_id,
            'comprador_id' => $merge->comprador_id,
            'video_ids' => $merge->video_ids,
        ]);

        try {
            $merger->merge($merge);
            LogProcessamento::info('merge.concluido', 'Merge concluído', [
                'merge_id' => $merge->id,
                'tamanho_bytes' => $merge->fresh()->tamanho_bytes,
            ]);
        } catch (Throwable $e) {
            LogProcessamento::error('merge.falhou', $e->getMessage(), [
                'merge_id' => $merge->id,
                'exception_class' => get_class($e),
            ]);
            $merge->update([
                'status' => VideoMerge::STATUS_FALHOU,
                'erro_msg' => mb_substr($e->getMessage(), 0, 1000),
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        VideoMerge::where('id', $this->mergeId)->update([
            'status' => VideoMerge::STATUS_FALHOU,
            'erro_msg' => mb_substr($e->getMessage(), 0, 1000),
        ]);
        LogProcessamento::critical('merge.job.failed', 'Job de merge no failed hook', [
            'merge_id' => $this->mergeId,
        ]);
    }
}
