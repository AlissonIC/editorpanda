<?php

namespace App\Jobs;

use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessarVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(public int $videoId) {}

    public function handle(): void
    {
        $video = Video::find($this->videoId);
        if (! $video) {
            return;
        }

        $video->update(['status' => Video::STATUS_PROCESSANDO]);

        // Placeholder: aqui no futuro entrará FFmpeg
        // (marca d'água, rotação, geração de thumbnail).
        // Por enquanto apenas simula o tempo de processamento.
        sleep(3);

        $video->update([
            'status' => Video::STATUS_CONCLUIDO,
            'arquivo_processado_path' => $video->arquivo_original_path,
            'processado_em' => now(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        Video::where('id', $this->videoId)->update([
            'status' => Video::STATUS_FALHOU,
            'erro_msg' => substr($e->getMessage(), 0, 500),
        ]);
    }
}
