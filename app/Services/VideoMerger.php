<?php

namespace App\Services;

use App\Models\LogProcessamento;
use App\Models\Video;
use App\Models\VideoMerge;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Concatena N vídeos processados num único mp4 usando o `concat demuxer` do FFmpeg.
 *
 * IMPORTANTE: exige que todos os vídeos de entrada tenham o mesmo codec/res/fps.
 * No nosso caso isso está garantido porque TODOS passam pelo VideoProcessor
 * (1080x1920, 30fps, h264 CRF 22, aac 128k). Sem re-encode = concat rápido.
 *
 * Fallback: se algum vídeo diferir (ex.: original em vez de processado), cai
 * pro concat filter (com re-encode) — mais lento mas robusto.
 */
class VideoMerger
{
    private const TIMEOUT_SECONDS = 1800;

    private string $ffmpegBin;

    public function __construct()
    {
        $this->ffmpegBin = (string) config('services.ffmpeg.bin', 'ffmpeg');
    }

    public function merge(VideoMerge $merge): void
    {
        $tempDir = storage_path('app/temp/merge-' . $merge->id);
        if (! is_dir($tempDir) && ! mkdir($tempDir, 0755, true) && ! is_dir($tempDir)) {
            throw new RuntimeException("Não foi possível criar temp: $tempDir");
        }

        try {
            $videos = Video::whereIn('id', $merge->video_ids)
                ->where('status', Video::STATUS_CONCLUIDO)
                ->whereNotNull('arquivo_processado_path')
                ->get();

            if ($videos->count() < 2) {
                throw new RuntimeException('Mínimo 2 vídeos processados. Encontrados: ' . $videos->count());
            }

            // Preserva a ordem original do array video_ids
            $ordem = array_flip($merge->video_ids);
            $videos = $videos->sortBy(fn ($v) => $ordem[$v->id] ?? PHP_INT_MAX)->values();

            // 1) Baixa cada processado pro temp local
            $arquivosLocais = [];
            foreach ($videos as $i => $video) {
                $local = $tempDir . DIRECTORY_SEPARATOR . sprintf('parte-%03d.mp4', $i);
                $this->downloadFromDisk($video->disk ?: 'local', $video->arquivo_processado_path, $local);
                $arquivosLocais[] = $local;
            }

            // 2) Gera concat list (formato do concat demuxer)
            $listPath = $tempDir . DIRECTORY_SEPARATOR . 'lista.txt';
            $conteudo = implode("\n", array_map(fn ($p) => "file '" . str_replace("'", "'\\''", $p) . "'", $arquivosLocais));
            file_put_contents($listPath, $conteudo);

            // 3) Executa FFmpeg
            $outputLocal = $tempDir . DIRECTORY_SEPARATOR . 'output.mp4';
            $this->runConcat($listPath, $outputLocal);

            // 4) Upload pro disco final
            $outputPath = $this->outputPathFor($merge);
            $this->uploadToDisk($merge->disk, $outputLocal, $outputPath);

            $merge->update([
                'output_path' => $outputPath,
                'tamanho_bytes' => filesize($outputLocal) ?: 0,
                'status' => VideoMerge::STATUS_CONCLUIDO,
                'concluido_em' => now(),
                'erro_msg' => null,
            ]);
        } finally {
            $this->rmrf($tempDir);
        }
    }

    private function runConcat(string $listPath, string $outputLocal): void
    {
        // Primeira tentativa: concat demuxer sem re-encode (rápido)
        $cmd = [
            $this->ffmpegBin, '-y', '-hide_banner', '-loglevel', 'error',
            '-f', 'concat', '-safe', '0', '-i', $listPath,
            '-c', 'copy', '-movflags', '+faststart',
            $outputLocal,
        ];
        $process = new Process($cmd);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();

        if (! $process->isSuccessful()) {
            // Fallback: re-encode (concat filter). Lento mas aguenta divergências.
            LogProcessamento::warning('merge.fallback_reencode', 'concat -c copy falhou, tentando re-encode', [
                'stderr' => mb_substr($process->getErrorOutput() ?: '', 0, 1500),
            ]);
            $cmd = [
                $this->ffmpegBin, '-y', '-hide_banner', '-loglevel', 'error',
                '-f', 'concat', '-safe', '0', '-i', $listPath,
                '-c:v', 'libx264', '-preset', 'medium', '-crf', '22',
                '-c:a', 'aac', '-b:a', '128k', '-ar', '48000',
                '-movflags', '+faststart',
                $outputLocal,
            ];
            $process = new Process($cmd);
            $process->setTimeout(self::TIMEOUT_SECONDS);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('ffmpeg concat falhou: ' . mb_substr($process->getErrorOutput() ?: '', 0, 500));
            }
        }

        if (! is_file($outputLocal) || filesize($outputLocal) < 1024) {
            throw new RuntimeException('ffmpeg concat gerou saída vazia.');
        }
    }

    private function outputPathFor(VideoMerge $merge): string
    {
        $donoId = $merge->user_id ?: ('c' . $merge->comprador_id);
        return "videos/mesclados/{$donoId}/{$merge->slug}.mp4";
    }

    private function downloadFromDisk(string $disk, string $remote, string $localPath): void
    {
        $storage = Storage::disk($disk);
        if (! $storage->exists($remote)) {
            throw new RuntimeException("Origem não existe no '$disk': $remote");
        }
        $in = $storage->readStream($remote);
        if (! $in) throw new RuntimeException("Falha stream leitura: $remote");
        $out = fopen($localPath, 'wb');
        if (! $out) { fclose($in); throw new RuntimeException("Falha abrir $localPath"); }
        stream_copy_to_stream($in, $out);
        fclose($out);
        fclose($in);
    }

    private function uploadToDisk(string $disk, string $localPath, string $remote): void
    {
        $stream = fopen($localPath, 'rb');
        if (! $stream) throw new RuntimeException("Falha ler saída: $localPath");
        try {
            Storage::disk($disk)->put($remote, $stream);
        } finally {
            if (is_resource($stream)) fclose($stream);
        }
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) return;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $f) {
            if ($f->isDir()) @rmdir($f->getPathname());
            else @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
