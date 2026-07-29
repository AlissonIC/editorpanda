<?php

namespace App\Services;

use App\Models\Album;
use App\Models\LogProcessamento;
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

    // Imagem enviada no lugar de vídeo é convertida num MP4 estático desta duração
    private const IMAGE_DURATION_SEC = 5;

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];

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

            // 5) Build & run — inclui rotação e espelhamento manual
            $config['rotacao'] = (int) ($video->rotacao ?? 0);
            $config['espelhado'] = (bool) ($video->espelhado ?? false);
            $config['is_imagem'] = $this->isImagem($video->arquivo_original_path);
            $outputPath = $tempDir . DIRECTORY_SEPARATOR . 'output.mp4';
            $cmd = $this->buildCommand($inputPath, $outputPath, $logoLocal, $meta, $config);
            $this->runFFmpeg($cmd);

            // 6) Upload da versão limpa (o "processado" — só liberado após compra)
            $processedRel = $this->processedPathFor($video);
            $this->uploadToDisk($video->disk ?: 'local', $outputPath, $processedRel);

            // 6b) Gera a versão de PREVIEW com watermarks a partir da versão limpa
            //     (segunda passagem de encode, mais rápida — CRF maior e preset faster).
            //     Este arquivo é o que roda na página pública do álbum.
            $previewPath = $tempDir . DIRECTORY_SEPARATOR . 'preview.mp4';
            $this->buildWatermarkedPreview($outputPath, $previewPath);
            $previewRel = $this->previewPathFor($video);
            $this->uploadToDisk($video->disk ?: 'local', $previewPath, $previewRel);

            // 7) Atualiza vídeo
            $duracao = $config['is_imagem']
                ? self::IMAGE_DURATION_SEC
                : (int) round($meta['duration'] ?? 0);
            $video->update([
                'arquivo_processado_path' => $processedRel,
                'arquivo_preview_path' => $previewRel,
                'status' => Video::STATUS_CONCLUIDO,
                'processado_em' => now(),
                'duracao_segundos' => $duracao,
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

    private function previewPathFor(Video $video): string
    {
        $original = $video->arquivo_original_path ?? '';
        if (str_contains($original, '/originais/')) {
            $rel = str_replace('/originais/', '/previews/', $original);
            $dir = dirname($rel);
            $base = pathinfo($rel, PATHINFO_FILENAME);
            return "$dir/$base.mp4";
        }
        return "videos/previews/{$video->user_id}/video-{$video->id}.mp4";
    }

    // ------------------------------------------------------------
    // Evento config
    // ------------------------------------------------------------
    private function getEventConfig(int $albumId): array
    {
        $album = Album::with('evento')->find($albumId);
        $ev = $album?->evento;

        return [
            // O "logo" no config interno do processor é a MARCA D'ÁGUA queimada
            // no vídeo. A logo de branding do evento (público) é campo separado.
            'logo_path' => $ev?->watermark_path,
            'logo_disk' => $ev?->watermark_disk,
            'logo_posicao' => $ev?->watermark_posicao ?: 'top-right',
            'logo_escala' => (float) ($ev?->watermark_escala ?: 0.15),
            'gradiente_habilitado' => (bool) ($ev?->gradiente_habilitado ?? false),
            'rosto_centralizar' => (bool) ($ev?->rosto_centralizar ?? false),
        ];
    }

    // ------------------------------------------------------------
    // FFmpeg / FFprobe
    // ------------------------------------------------------------
    private function isImagem(?string $path): bool
    {
        $ext = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION) ?: '');
        return in_array($ext, self::IMAGE_EXTENSIONS, true);
    }

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

        // Transformações manuais (espelhamento + rotação) aplicadas ANTES do
        // scale/crop. Ordem: hflip → transpose. Coincide com o CSS do preview,
        // que usa `rotate(...) scaleX(-1)` (CSS aplica da direita pra esquerda).
        $espelhado = ! empty($config['espelhado']);
        $rotacao = (int) ($config['rotacao'] ?? 0);

        $preFilters = '';
        if ($espelhado) $preFilters .= 'hflip,';
        $preFilters .= match ($rotacao) {
            90 => 'transpose=1,',                 // clockwise
            180 => 'transpose=1,transpose=1,',    // duas de 90
            270 => 'transpose=2,',                // counter-clockwise
            default => '',
        };

        // "Cover crop" pra 1080x1920: escala mantendo aspect ratio até que
        // AMBAS dimensões cubram o alvo (force_original_aspect_ratio=increase),
        // depois corta o excesso centralizado. Funciona para qualquer aspect
        // (retrato, paisagem, quadrado) e qualquer tamanho — inclusive imagens
        // pequenas tipo 405x552 (que quebravam o `scale=W:-2,crop=W:H`).
        $vFilter = "{$preFilters}scale={$W}:{$H}:force_original_aspect_ratio=increase,crop={$W}:{$H},setsar=1";

        $parts = ["[0:v]{$vFilter}[v0]"];
        $lastLabel = '[v0]';

        // Gradiente REAL na região do logo (se habilitado).
        // Antes usávamos drawbox com fill sólido — resultado era um bloco escuro
        // óbvio, não um degradê. Agora gera um source `color` do mesmo tamanho
        // da faixa, aplica alpha via `geq` (fórmula linear em Y), e faz overlay
        // no vídeo na posição correta. Cores/alphas escolhidas pra fundir com
        // qualquer cena sem afogar o fundo — apenas escurecer o suficiente
        // pra dar contraste ao logo.
        if ($config['gradiente_habilitado']) {
            $pos = $config['logo_posicao'];
            $gradH = intdiv($H, 3); // 640 numa saída 1920 — cobre a região do logo
            $alphaMax = 200;         // ~78% no ponto mais escuro; 0 no ponto claro

            if (str_starts_with($pos, 'top')) {
                // Escuro no topo, transparente na base. Overlay em y=0.
                $alphaExpr = "{$alphaMax}*(1-Y/H)";
                $overlayY = '0';
            } elseif (str_starts_with($pos, 'bottom')) {
                // Escuro na base, transparente no topo. Overlay em y=H-gradH.
                $alphaExpr = "{$alphaMax}*(Y/H)";
                $overlayY = (string) ($H - $gradH);
            } else {
                // Meio: gradiente radial vertical (escuro no centro, fade pros lados).
                // sin(PI*Y/H) sobe de 0→1→0 conforme Y percorre 0→H/2→H.
                $alphaExpr = "{$alphaMax}*sin(PI*Y/H)";
                $overlayY = (string) intdiv($H - $gradH, 2);
            }

            // Source do gradiente: color preto opaco + geq zerando alpha por linha.
            // `d=1` limita a 1s de frames; overlay usa o último frame para o resto
            // do vídeo (eof_action=repeat, o default), então o custo do geq é fixo
            // e não escala com a duração do vídeo — só com o tamanho da faixa.
            $parts[] = "color=c=black:s={$W}x{$gradH}:d=1:r=1,format=rgba,geq=r=0:g=0:b=0:a='{$alphaExpr}'[grad]";
            $parts[] = "{$lastLabel}[grad]overlay=x=0:y={$overlayY}[v1]";
            $lastLabel = '[v1]';
        }

        // Inputs: 0 = vídeo/imagem, 1 = logo (se houver)
        $isImagem = ! empty($config['is_imagem']);
        $inputs = [];
        if ($isImagem) {
            // Loop + duração fixa transformam a imagem estática num MP4 curto
            $inputs = ['-loop', '1', '-t', (string) self::IMAGE_DURATION_SEC, '-i', $input];
        } else {
            $inputs = ['-i', $input];
        }

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

        $cmd = [
            $this->ffmpegBin, '-y', '-hide_banner', '-loglevel', 'error',
            ...$inputs,
            '-filter_complex', $filterComplex,
            '-map', $lastLabel,
        ];

        if ($isImagem) {
            // Sem áudio: imagem não tem trilha
            $cmd[] = '-an';
        } else {
            $cmd[] = '-map';
            $cmd[] = '0:a?';
        }

        return [
            ...$cmd,
            '-r', (string) self::OUT_FPS,
            '-c:v', 'libx264',
            '-preset', self::OUT_PRESET,
            '-crf', (string) self::OUT_CRF,
            '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart',
            ...(! $isImagem ? [
                '-c:a', 'aac',
                '-b:a', self::OUT_AUDIO_BITRATE,
                '-ar', '48000',
            ] : []),
            $output,
        ];
    }

    private function positionCoords(string $pos): array
    {
        $m = 40;
        return match ($pos) {
            'top-left'      => [(string) $m,        (string) $m],
            'top-center'    => ['(W-w)/2',          (string) $m],
            'top-right'     => ["W-w-{$m}",         (string) $m],
            'middle-left'   => [(string) $m,        '(H-h)/2'],
            'center'        => ['(W-w)/2',          '(H-h)/2'],
            'middle-right'  => ["W-w-{$m}",         '(H-h)/2'],
            'bottom-left'   => [(string) $m,        "H-h-{$m}"],
            'bottom-center' => ['(W-w)/2',          "H-h-{$m}"],
            'bottom-right'  => ["W-w-{$m}",         "H-h-{$m}"],
            default         => ["W-w-{$m}",         (string) $m],
        };
    }

    private function runFFmpeg(array $cmd): void
    {
        $process = new Process($cmd);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();
        if (! $process->isSuccessful()) {
            $stderr = $process->getErrorOutput() ?: 'sem stderr';
            // Guarda o stderr COMPLETO no log em DB (o erro_msg do vídeo é truncado)
            LogProcessamento::error('ffmpeg.error', 'ffmpeg terminou com erro', [
                'exit_code' => $process->getExitCode(),
                'stderr' => mb_substr($stderr, 0, 4000),
                'stderr_tail' => mb_substr($stderr, -1000),
            ]);
            throw new RuntimeException('ffmpeg falhou: ' . substr($stderr, 0, 500));
        }

        // Sanity check: o último argumento é o path de saída
        $outputPath = end($cmd);
        if (! is_string($outputPath) || ! is_file($outputPath)) {
            LogProcessamento::error('ffmpeg.no_output', 'ffmpeg exit 0 sem arquivo de saída', ['output_path' => (string) $outputPath]);
            throw new RuntimeException('ffmpeg terminou com exit 0 mas não gerou o arquivo de saída.');
        }
        $size = filesize($outputPath);
        if ($size === false || $size < 1024) {
            LogProcessamento::error('ffmpeg.output_vazio', "Saída ffmpeg com {$size} bytes — provável erro silencioso", ['tamanho' => $size]);
            throw new RuntimeException("ffmpeg gerou arquivo vazio ou minúsculo ({$size} bytes) — provável erro silencioso.");
        }
    }

    /**
     * Gera a versão preview a partir do MP4 limpo já processado:
     *   - Downscale pra 540x960 (metade) — captura em screen-record fica ruim
     *   - Watermark ESTÁTICA: PNG gerada via PHP GD com texto tiled rotacionado
     *     em diagonal (padrão tipo "SAMPLE"); sobreposta pelo FFmpeg com overlay
     *   - CRF 28 + preset veryfast — arquivo leve, encode rápido
     *   - Audio re-encodado em baixa taxa (48kbps) — impede reaproveitamento
     *
     * A PNG é gerada dinamicamente pra cada job (~200ms). Não cacheamos porque
     * texto/tamanho podem mudar por config e o overhead é irrelevante.
     */
    private function buildWatermarkedPreview(string $cleanInput, string $previewOutput): void
    {
        // Preview em 540x960 (retrato, metade do processado 1080x1920)
        $previewW = 540;
        $previewH = 960;

        // Gera a PNG do watermark ao lado do output (mesma pasta temp — é limpa ao fim)
        $watermarkPng = dirname($previewOutput) . DIRECTORY_SEPARATOR . 'watermark-tiled.png';
        $this->generateDiagonalWatermarkPng($watermarkPng, $previewW, $previewH);

        $cmd = [
            $this->ffmpegBin, '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $cleanInput,
            '-i', $watermarkPng,
            '-filter_complex',
                "[0:v]scale={$previewW}:{$previewH}:flags=lanczos[scaled];[scaled][1:v]overlay=0:0[out]",
            '-map', '[out]',
            '-map', '0:a?',              // audio opcional (imagem não tem)
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-crf', '28',
            '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart',
            '-c:a', 'aac',
            '-b:a', '48k',
            '-ar', '44100',
            $previewOutput,
        ];

        $this->runFFmpeg($cmd);
    }

    /**
     * Cria PNG semi-transparente com o texto do site tiled em diagonal.
     * Padrão denso — cobre o frame inteiro em 30°. Halo branco dá contraste
     * em cenas claras E escuras. Remover em pós exige inpainting que arruína
     * o vídeo original.
     */
    private function generateDiagonalWatermarkPng(string $outputPath, int $width, int $height): void
    {
        if (! function_exists('imagettftext')) {
            throw new RuntimeException(
                'PHP GD sem suporte a FreeType — instale php-gd com --with-freetype.'
            );
        }

        $font = $this->resolveWatermarkFont();
        $texto = (string) config('services.watermark.texto', 'PANDAVIDEO');

        // Canvas RGBA totalmente transparente
        $img = imagecreatetruecolor($width, $height);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);
        imagealphablending($img, true);

        // Alpha em GD é invertido: 0=opaco, 127=transparente
        $preto  = imagecolorallocatealpha($img, 0, 0, 0, 45);        // ~65% opaco
        $branco = imagecolorallocatealpha($img, 255, 255, 255, 40);  // ~68% opaco

        $fontSize = 16;
        $angle = 30; // graus (sentido anti-horário no GD)

        // Grid diagonal — quanto menor stepX/stepY, mais denso o padrão.
        $stepX = 110;
        $stepY = 90;

        // Extra pra fora do frame — texto rotacionado precisa extrapolar
        // as bordas pra cobrir cantos sem "buracos".
        $overshoot = 120;

        for ($y = -$overshoot; $y < $height + $overshoot; $y += $stepY) {
            // Deslocamento X alternado por linha — efeito "tijolo diagonal"
            $offset = ((intdiv($y + $overshoot, $stepY)) % 2) === 0 ? 0 : intdiv($stepX, 2);
            for ($x = -$overshoot; $x < $width + $overshoot; $x += $stepX) {
                $xPos = $x + $offset;
                // Halo branco (1px sudeste) — contraste em fundo escuro
                imagettftext($img, $fontSize, $angle, $xPos + 1, $y + 1, $branco, $font, $texto);
                imagettftext($img, $fontSize, $angle, $xPos,      $y,      $preto,  $font, $texto);
            }
        }

        if (! imagepng($img, $outputPath)) {
            imagedestroy($img);
            throw new RuntimeException("Falha ao salvar PNG do watermark em: {$outputPath}");
        }
        imagedestroy($img);
    }

    /**
     * Descobre o caminho de uma fonte TTF/OTF utilizável pelo drawtext.
     * Prioridade: config explícita → auto-detect por SO. Se falhar, lança.
     */
    private function resolveWatermarkFont(): string
    {
        $configured = (string) config('services.watermark.font', '');
        if ($configured !== '' && is_file($configured)) return $configured;

        $candidatos = PHP_OS_FAMILY === 'Windows'
            ? [
                'C:/Windows/Fonts/arialbd.ttf',
                'C:/Windows/Fonts/arial.ttf',
                'C:/Windows/Fonts/segoeuib.ttf',
            ]
            : [
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            ];
        foreach ($candidatos as $c) {
            if (is_file($c)) return $c;
        }
        throw new RuntimeException(
            'Nenhuma fonte encontrada para watermark. Configure WATERMARK_FONT no .env apontando para um arquivo .ttf/.otf.'
        );
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
