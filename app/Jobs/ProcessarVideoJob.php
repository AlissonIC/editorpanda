<?php

namespace App\Jobs;

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
            Log::warning('ProcessarVideoJob: vídeo não encontrado', ['video_id' => $this->videoId]);
            return;
        }

        // Só processa vídeos "pendente". Ignora se alguém já pegou (concurrency-safe).
        if ($video->status !== Video::STATUS_PENDENTE) {
            Log::info('ProcessarVideoJob: vídeo não está pendente', [
                'video_id' => $video->id,
                'status' => $video->status,
            ]);
            return;
        }

        $video->update(['status' => Video::STATUS_PROCESSANDO, 'erro_msg' => null]);

        try {
            $processor->process($video);
            Log::info('Vídeo processado com sucesso', ['video_id' => $video->id]);
        } catch (Throwable $e) {
            Log::error('Falha ao processar vídeo', [
                'video_id' => $video->id,
                'msg' => $e->getMessage(),
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
    }
}
