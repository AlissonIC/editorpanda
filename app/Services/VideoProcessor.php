<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * VideoProcessor — Pipeline FFmpeg em PHP.
 *
 * Fluxo:
 *   1. Baixa o original do disco (local ou S3) para uma pasta temp
 *   2. Baixa o logo do evento (se houver)
 *   3. ffprobe: descobre W×H
 *   4. Monta o comando ffmpeg (crop 9:16 + logo + gradiente)
 *   5. Executa via Symfony Process com timeout longo
 *   6. Sobe o resultado no mesmo disco (path videos/processados/…)
 *   7. Limpa o temp
 *
 * Não faz gestão de status — quem chama (o Job) é responsável.
 */
class VideoProcessor
{
    private const OUT_WIDTH = 1080;
    private const OUT_HEIGHT = 1920;
    private const OUT_FPS = 30;
    private const OUT_CRF = 22;
    private const OUT_PRESET = 'medium';
    private const OUT_AUDIO_BITRATE = '128k';
    private const TIMEOUT_SECONDS = 1800; // 30 min

    private string $ffmpegBin;
    private string $ffprobeBin;

    public function __construct()
    {
        $this->ffmpegBin = (string) config('services.ffmpeg.bin', 'ffmpeg');
        $this->ffprobeBin = (string) config('services.ffmpeg.ffprobe', 'ffprobe');
    }

    /**
     * Processa um vídeo. Se sucesso, atualiza arquivo_processado_path e o status
     * para "concluido". Em falha, lança RuntimeException — deixe o Job tratar.
     */
    public function process(Video $video): void
    {
        $tempDir = storage_path('app/temp/processing-' . $video->id);
        if (! is_dir($tempDir) && ! mkdir($tempDir, 0755, true) && ! is_dir($tempDir)) {
            throw new RuntimeException("Não foi possível criar pasta temp: $tempDir");
        }

        try {
            // 1) Download do original
            $ext = pathinfo($video->arquivo_original_path, PATHINFO_EXTENSION) ?: 'mp4';
            $inputPath = $tempDir . DIRECTORY_SEPARATOR . 'input.' . $ext;
            $this->downloadFromDisk($video->disk ?: 'local', $video->arquivo_original_path, $inputPath);

            // 2) Config do evento
            $config = $this->getEventConfig($video->album_id);

            // 3) Logo (opcional)
            $logoLocal = null;
            if ($config['logo_path']) {
                $lExt = pathinfo($config['logo_path'], PATHINFO_EXTENSION) ?: 'png';
                $logoLocal = $tempDir . DIRECTORY_SEPARATOR . 'logo.' . $lExt;
                $this->downloadFromDisk($config['logo_disk'] ?: 'local', $config['logo_path'], $logoLocal);
            }

            // 4) Probe
            $meta = $this->probe($inputPath);

            // 5) Build & run
            $outputPath = $tempDir . DIRECTORY_SEPARATOR . 'output.mp4';
            $cmd = $this->buildCommand($inputPath, $outputPath, $logoLocal, $meta, $config);
            $this->runFFmpeg($cmd);

            // 6) Upload
            $processedRel = $this->processedPathFor($video);
            $this->uploadToDisk($video->disk ?: 'local', $outputPath, $processedRel);

            // 7) Atualiza vídeo
            $video->update([
                'arquivo_processado_path' => $processedRel,
                'status' => Video::STATUS_CONCLUIDO,
                'processado_em' => now(),
                'duracao_segundos' => (int) round($meta['duration'] ?? 0),
                'erro_msg' => null,
            ]);
        } finally {
            $this->rmrf($tempDir);
        }
    }

    // ------------------------------------------------------------
    // Storage helpers (usam Storage::disk() → funciona pra local + s3)
    // ------------------------------------------------------------
    private function downloadFromDisk(string $disk, string $remote, string $localPath): void
    {
        $storage = Storage::disk($disk);
        if (! $storage->exists($remote)) {
            throw new RuntimeException("Origem não existe no disco '$disk': $remote");
        }
        $in = $storage->readStream($remote);
        if (! $in) {
            throw new RuntimeException("Falha ao abrir stream de leitura: $remote");
        }
        $out = fopen($localPath, 'wb');
        if (! $out) {
            fclose($in);
            throw new RuntimeException("Falha ao abrir $localPath para escrita");
        }
        stream_copy_to_stream($in, $out);
        fclose($out);
        fclose($in);
    }

    private function uploadToDisk(string $disk, string $localPath, string $remote): void
    {
        $stream = fopen($localPath, 'rb');
        if (! $stream) {
            throw new RuntimeException("Falha ao ler saída: $localPath");
        }
        try {
            Storage::disk($disk)->put($remote, $stream);
        } finally {
            if (is_resource($stream)) fclose($stream);
        }
    }

    private function processedPathFor(Video $video): string
    {
        $original = $video->arquivo_original_path ?? '';
        if (str_contains($original, '/originais/')) {
            $rel = str_replace('/originais/', '/processados/', $original);
            // Força extensão .mp4 (o output é sempre mp4)
            $dir = dirname($rel);
            $base = pathinfo($rel, PATHINFO_FILENAME);
            return "$dir/$base.mp4";
        }
        return "videos/processados/{$video->user_id}/video-{$video->id}.mp4";
    }

    // ------------------------------------------------------------
    // Evento config
    // ------------------------------------------------------------
    private function getEventConfig(int $albumId): array
    {
        $album = Album::with('evento')->find($albumId);
        $ev = $album?->evento;

        return [
            'logo_path' => $ev?->logo_path,
            'logo_disk' => $ev?->logo_disk,
            'logo_posicao' => $ev?->logo_posicao ?: 'top-right',
            'logo_escala' => (float) ($ev?->logo_escala ?: 0.15),
            'gradiente_habilitado' => (bool) ($ev?->gradiente_habilitado ?? false),
            'rosto_centralizar' => (bool) ($ev?->rosto_centralizar ?? false),
        ];
    }

    // ------------------------------------------------------------
    // FFmpeg / FFprobe
    // ------------------------------------------------------------
    private function probe(string $path): array
    {
        $process = new Process([
            $this->ffprobeBin, '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height,duration',
            '-show_entries', 'format=duration',
            '-of', 'json', $path,
        ]);
        $process->setTimeout(60);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('ffprobe falhou: ' . substr($process->getErrorOutput(), 0, 300));
        }
        $data = json_decode($process->getOutput() ?: '{}', true) ?? [];
        $stream = $data['streams'][0] ?? [];
        return [
            'width' => (int) ($stream['width'] ?? 0),
            'height' => (int) ($stream['height'] ?? 0),
            'duration' => (float) ($stream['duration'] ?? ($data['format']['duration'] ?? 0)),
        ];
    }

    private function buildCommand(string $input, string $output, ?string $logo, array $meta, array $config): array
    {
        $W = self::OUT_WIDTH;
        $H = self::OUT_HEIGHT;
        $w = $meta['width'] ?: 1;
        $h = $meta['height'] ?: 1;

        // Filtro base 1080x1920
        if ($w >= $h) {
            // Paisagem/quadrado: preenche altura → corta lateral centrado (zoom)
            $vFilter = "scale=-2:{$H},crop={$W}:{$H}:(iw-{$W})/2:0,setsar=1";
        } else {
            // Retrato: preenche largura → corta vertical centrado
            $vFilter = "scale={$W}:-2,crop={$W}:{$H}:0:(ih-{$H})/2,setsar=1";
        }

        $parts = ["[0:v]{$vFilter}[v0]"];
        $lastLabel = '[v0]';

        // Gradiente semi-transparente na região do logo (se habilitado)
        if ($config['gradiente_habilitado']) {
            $pos = $config['logo_posicao'];
            if (str_starts_with($pos, 'top')) {
                $y = '0'; $bh = 'ih/3';
            } elseif (str_starts_with($pos, 'bottom')) {
                $y = 'ih*2/3'; $bh = 'ih/3';
            } else {
                $y = '(ih-ih/3)/2'; $bh = 'ih/3';
            }
            $parts[] = "{$lastLabel}drawbox=x=0:y={$y}:w=iw:h={$bh}:color=black@0.35:t=fill[v1]";
            $lastLabel = '[v1]';
        }

        // Inputs: 0 = vídeo, 1 = logo (se houver)
        $inputs = ['-i', $input];

        if ($logo) {
            $inputs[] = '-i';
            $inputs[] = $logo;

            $logoW = (int) ($W * $config['logo_escala']);
            $parts[] = "[1:v]scale={$logoW}:-1[logo]";

            [$x, $y2] = $this->positionCoords($config['logo_posicao']);
            $parts[] = "{$lastLabel}[logo]overlay=x={$x}:y={$y2}[vout]";
            $lastLabel = '[vout]';
        }

        $filterComplex = implode(';', $parts);

        return [
            $this->ffmpegBin, '-y', '-hide_banner', '-loglevel', 'error',
            ...$inputs,
            '-filter_complex', $filterComplex,
            '-map', $lastLabel,
            '-map', '0:a?',
            '-r', (string) self::OUT_FPS,
            '-c:v', 'libx264',
            '-preset', self::OUT_PRESET,
            '-crf', (string) self::OUT_CRF,
            '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart',
            '-c:a', 'aac',
            '-b:a', self::OUT_AUDIO_BITRATE,
            '-ar', '48000',
            $output,
        ];
    }

    private function positionCoords(string $pos): array
    {
        $m = 40;
        return match ($pos) {
            'top-left'     => [(string) $m, (string) $m],
            'top-right'    => ["W-w-{$m}", (string) $m],
            'bottom-left'  => [(string) $m, "H-h-{$m}"],
            'bottom-right' => ["W-w-{$m}", "H-h-{$m}"],
            'center'       => ['(W-w)/2', '(H-h)/2'],
            default        => ["W-w-{$m}", (string) $m],
        };
    }

    private function runFFmpeg(array $cmd): void
    {
        $process = new Process($cmd);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('ffmpeg falhou: ' . substr($process->getErrorOutput() ?: 'sem stderr', 0, 500));
        }

        // Sanity check: o último argumento é o path de saída
        $outputPath = end($cmd);
        if (! is_string($outputPath) || ! is_file($outputPath)) {
            throw new RuntimeException('ffmpeg terminou com exit 0 mas não gerou o arquivo de saída.');
        }
        $size = filesize($outputPath);
        if ($size === false || $size < 1024) {
            throw new RuntimeException("ffmpeg gerou arquivo vazio ou minúsculo ({$size} bytes) — provável erro silencioso.");
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
