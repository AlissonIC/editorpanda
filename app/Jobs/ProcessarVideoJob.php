<?php

namespace App\Jobs;

use App\Models\LogProcessamento;
use App\Models\Video;
use App\Services\VideoProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job de processamento de vídeo.
 *
 * Executa a pipeline FFmpeg (crop 9:16 + logo + gradiente) via VideoProcessor.
 * Consumo: `php artisan queue:work` (múltiplas instâncias para paralelismo).
 *
 * Timeout longo (30 min) para acomodar vídeos grandes. Sem retry automático:
 * falhas geralmente são de configuração ou dados — não adianta reprocessar
 * a mesma coisa (o admin usa a tela de "Processamento" para reenfileirar).
 */
class ProcessarVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1920; // FFmpeg timeout (1800) + 120s de margem para I/O do disco/S3

    public function __construct(public int $videoId) {}

    public function handle(VideoProcessor $processor): void
    {
        $video = Video::find($this->videoId);
        if (! $video) {
            LogProcessamento::warning('video.nao_encontrado', 'Vídeo não encontrado no banco', ['video_id' => $this->videoId]);
            return;
        }

        // Só processa vídeos "pendente". Ignora se alguém já pegou (concurrency-safe).
        if ($video->status !== Video::STATUS_PENDENTE) {
            LogProcessamento::info('video.ignorado', "Job ignorado — status atual: {$video->status}", [
                'video_id' => $video->id,
                'user_id' => $video->user_id,
                'status_atual' => $video->status,
            ]);
            return;
        }

        $video->update(['status' => Video::STATUS_PROCESSANDO, 'erro_msg' => null]);

        LogProcessamento::info('video.processando', 'Início do processamento', [
            'video_id' => $video->id,
            'user_id' => $video->user_id,
            'tamanho_bytes' => $video->tamanho_bytes,
            'disk' => $video->disk,
        ]);

        $inicio = microtime(true);

        try {
            $processor->process($video);

            LogProcessamento::info('video.concluido', 'Vídeo processado com sucesso', [
                'video_id' => $video->id,
                'user_id' => $video->user_id,
                'duracao_processamento_s' => (int) round(microtime(true) - $inicio),
                'duracao_video_s' => $video->fresh()->duracao_segundos,
            ]);
        } catch (Throwable $e) {
            LogProcessamento::error('video.falhou', $e->getMessage(), [
                'video_id' => $video->id,
                'user_id' => $video->user_id,
                'duracao_processamento_s' => (int) round(microtime(true) - $inicio),
                'exception_class' => get_class($e),
                'trace_head' => mb_substr($e->getTraceAsString(), 0, 1500),
            ]);
            $video->update([
                'status' => Video::STATUS_FALHOU,
                'erro_msg' => substr($e->getMessage(), 0, 500),
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        Video::where('id', $this->videoId)->update([
            'status' => Video::STATUS_FALHOU,
            'erro_msg' => substr($e->getMessage(), 0, 500),
        ]);

        LogProcessamento::critical('job.failed', 'Job entrou no failed hook (worker pode ter crashado)', [
            'video_id' => $this->videoId,
            'exception_class' => get_class($e),
            'mensagem' => $e->getMessage(),
        ]);
    }
}
