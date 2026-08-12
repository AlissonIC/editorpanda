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

    // Threads por encode do FFmpeg. Limitar é essencial pra throughput em
    // servidor multi-worker: libx264 sem -threads usa todos os cores, então
    // 4 workers competiriam por 12 cores e context switch destruiria o ganho.
    // 3 threads × 4 workers = 12 cores dedicados, 4 vídeos em paralelo.
    private const OUT_THREADS = 3;

    // Qualidade JPEG do processado de imagem (2-31, menor = melhor; 3 = alta).
    private const JPG_QUALITY = 3;

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];

    // HEIC/HEIF precisam de pre-conversão porque ffmpeg 8 do Ubuntu não tem
    // demuxer nativo — usamos heif-convert (pacote libheif-examples).
    private const HEIC_EXTENSIONS = ['heic', 'heif'];

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
            $ext = strtolower(pathinfo($video->arquivo_original_path, PATHINFO_EXTENSION) ?: 'mp4');
            $inputPath = $tempDir . DIRECTORY_SEPARATOR . 'input.' . $ext;
            $this->downloadFromDisk($video->disk ?: 'local', $video->arquivo_original_path, $inputPath);

            $isImagem = $this->isImagem($video->arquivo_original_path);

            // HEIC/HEIF: ffmpeg 8 do Ubuntu não tem demuxer → pre-converte pra JPG.
            // Retorna o novo caminho pro pipeline. O input.heic original é ignorado.
            if ($isImagem && in_array($ext, self::HEIC_EXTENSIONS, true)) {
                $inputPath = $this->preConvertHeic($inputPath, $tempDir);
            }

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

            // 5) Config comum
            $config['rotacao'] = (int) ($video->rotacao ?? 0);
            $config['espelhado'] = (bool) ($video->espelhado ?? false);
            $config['is_imagem'] = $isImagem;

            // Álbuns gratuitos: preview não precisa de marca d'água (não há venda a
            // proteger — o vídeo/imagem público JÁ É o produto final).
            $album = Album::with('evento')->find($video->album_id);
            $isGratuito = $album?->ehGratuito() ?? false;

            // 6) Encode principal — extensão do output depende do tipo
            $outExt = $isImagem ? 'jpg' : 'mp4';
            $outputPath = $tempDir . DIRECTORY_SEPARATOR . 'output.' . $outExt;

            $cmd = $isImagem
                ? $this->buildImageCommand($inputPath, $outputPath, $logoLocal, $meta, $config)
                : $this->buildCommand($inputPath, $outputPath, $logoLocal, $meta, $config);
            $this->runFFmpeg($cmd);

            // 7) Upload da versão limpa (só liberada após compra em álbuns pagos)
            $processedRel = $this->processedPathFor($video, $outExt);
            $this->uploadToDisk($video->disk ?: 'local', $outputPath, $processedRel);

            // 8) Preview público
            if ($isGratuito) {
                // Gratuito: preview aponta pro mesmo arquivo processado — não gera
                // segunda passagem nem consome storage duplicado.
                $previewRel = $processedRel;
            } else {
                $previewPath = $tempDir . DIRECTORY_SEPARATOR . 'preview.' . $outExt;
                if ($isImagem) {
                    $this->buildWatermarkedPreviewImagem($outputPath, $previewPath);
                } else {
                    $this->buildWatermarkedPreview($outputPath, $previewPath);
                }
                $previewRel = $this->previewPathFor($video, $outExt);
                $this->uploadToDisk($video->disk ?: 'local', $previewPath, $previewRel);
            }

            // 9) Atualiza registro
            $duracao = $isImagem ? 0 : (int) round($meta['duration'] ?? 0);
            $update = [
                'arquivo_processado_path' => $processedRel,
                'arquivo_preview_path' => $previewRel,
                'status' => Video::STATUS_CONCLUIDO,
                'processado_em' => now(),
                'duracao_segundos' => $duracao,
                'erro_msg' => null,
            ];

            // 10) Thumbnail — fallback server-side quando o browser não conseguiu
            // gerar (típico com HEIC: browsers não decodificam, extractImageThumbnail
            // falha, thumbnail_path fica NULL). Refetch pra pegar update do browser
            // que pode ter chegado entre o dispatch e agora.
            $video->refresh();
            if (empty($video->thumbnail_path)) {
                $thumbLocal = $tempDir . DIRECTORY_SEPARATOR . 'thumb.jpg';
                $thumbSource = $outputPath; // processed (mp4 ou jpg — ffmpeg lida com ambos)
                $this->buildThumbnail($thumbSource, $thumbLocal, $isImagem, $meta['duration'] ?? 0);
                $thumbRel = "thumbnails/{$video->user_id}/video-{$video->id}.jpg";
                $this->uploadToDisk($video->disk ?: 'local', $thumbLocal, $thumbRel);
                $update['thumbnail_path'] = $thumbRel;
            }

            $video->update($update);
        } finally {
            $this->rmrf($tempDir);
        }
    }

    /**
     * Gera thumbnail 150x150 JPEG (cover-crop centralizado). Fallback server-side
     * pra quando o browser não conseguiu (ex: HEIC — nenhum browser desktop decodifica).
     */
    private function buildThumbnail(string $source, string $output, bool $isImagem, float $duration): void
    {
        $seekArgs = [];
        if (! $isImagem && $duration > 0) {
            // Vídeo: pega frame em ~10% da duração (mesmo comportamento do JS)
            $seek = max(0.05, min($duration * 0.1, $duration - 0.1));
            $seekArgs = ['-ss', sprintf('%.2f', $seek)];
        }

        $cmd = [
            $this->ffmpegBin, '-y', '-hide_banner', '-loglevel', 'error',
            ...$seekArgs,
            '-i', $source,
            '-vf', 'scale=150:150:force_original_aspect_ratio=increase,crop=150:150',
            '-frames:v', '1',
            '-q:v', '4',
            '-threads', '1',
            $output,
        ];
        $this->runFFmpeg($cmd);
    }

    /**
     * Converte HEIC/HEIF pra JPG usando heif-convert. Retorna o novo caminho.
     * Necessário porque ffmpeg 8 do Ubuntu não tem demuxer HEIF nativo.
     */
    private function preConvertHeic(string $heicPath, string $tempDir): string
    {
        $jpgPath = $tempDir . DIRECTORY_SEPARATOR . 'input-heic.jpg';
        $process = new Process(['heif-convert', '-q', '92', $heicPath, $jpgPath]);
        $process->setTimeout(120);
        $process->run();
        if (! $process->isSuccessful() || ! is_file($jpgPath)) {
            throw new RuntimeException(
                'heif-convert falhou: ' . substr($process->getErrorOutput() ?: 'sem stderr', 0, 300)
            );
        }
        return $jpgPath;
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

    private function processedPathFor(Video $video, string $ext = 'mp4'): string
    {
        $original = $video->arquivo_original_path ?? '';
        if (str_contains($original, '/originais/')) {
            $rel = str_replace('/originais/', '/processados/', $original);
            $dir = dirname($rel);
            $base = pathinfo($rel, PATHINFO_FILENAME);
            return "$dir/$base.$ext";
        }
        return "videos/processados/{$video->user_id}/video-{$video->id}.$ext";
    }

    private function previewPathFor(Video $video, string $ext = 'mp4'): string
    {
        $original = $video->arquivo_original_path ?? '';
        if (str_contains($original, '/originais/')) {
            $rel = str_replace('/originais/', '/previews/', $original);
            $dir = dirname($rel);
            $base = pathinfo($rel, PATHINFO_FILENAME);
            return "$dir/$base.$ext";
        }
        return "videos/previews/{$video->user_id}/video-{$video->id}.$ext";
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

        // Inputs: 0 = vídeo, 1 = logo (se houver). Imagens usam buildImageCommand.
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
            '-threads', (string) self::OUT_THREADS,
            '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart',
            '-c:a', 'aac',
            '-b:a', self::OUT_AUDIO_BITRATE,
            '-ar', '48000',
            $output,
        ];
    }

    /**
     * Constrói o comando FFmpeg para processamento de IMAGEM (single frame → JPG).
     *
     * PRESERVA dimensões e proporção originais — só aplica rotação/espelhamento
     * (transformações explícitas do usuário) e sobrepõe gradiente/logo. Nada
     * de scale/crop automático. Isso significa que fotos gigantes (ex: 6000x4000)
     * saem no mesmo tamanho — se virarem problema de storage, aí decidimos
     * cap por regra separada, mas o processador respeita o input.
     */
    private function buildImageCommand(string $input, string $output, ?string $logo, array $meta, array $config): array
    {
        $espelhado = ! empty($config['espelhado']);
        $rotacao = (int) ($config['rotacao'] ?? 0);

        // Dimensões pós-rotação (transpose 90/270 troca W e H).
        $origW = (int) ($meta['width'] ?? 0);
        $origH = (int) ($meta['height'] ?? 0);
        if ($origW <= 0 || $origH <= 0) {
            // Probe falhou — usa fallback conservador (não deveria acontecer).
            $origW = self::OUT_WIDTH;
            $origH = self::OUT_HEIGHT;
        }
        $W = in_array($rotacao, [90, 270], true) ? $origH : $origW;
        $H = in_array($rotacao, [90, 270], true) ? $origW : $origH;

        // Sem scale/crop — só rotate/mirror se pedido. Se nenhum, `null` é
        // filter pass-through do ffmpeg (não altera nada, mas dá um label pro
        // filter_complex referenciar).
        $preFilters = '';
        if ($espelhado) $preFilters .= 'hflip,';
        $preFilters .= match ($rotacao) {
            90 => 'transpose=1,',
            180 => 'transpose=1,transpose=1,',
            270 => 'transpose=2,',
            default => '',
        };
        $preFilters = rtrim($preFilters, ',');
        if ($preFilters === '') $preFilters = 'null';

        $parts = ["[0:v]{$preFilters}[v0]"];
        $lastLabel = '[v0]';

        // Gradiente cobre 1/3 da altura ORIGINAL — proporcional à imagem.
        if ($config['gradiente_habilitado']) {
            $pos = $config['logo_posicao'];
            $gradH = max(1, intdiv($H, 3));
            $alphaMax = 200;

            if (str_starts_with($pos, 'top')) {
                $alphaExpr = "{$alphaMax}*(1-Y/H)";
                $overlayY = '0';
            } elseif (str_starts_with($pos, 'bottom')) {
                $alphaExpr = "{$alphaMax}*(Y/H)";
                $overlayY = (string) ($H - $gradH);
            } else {
                $alphaExpr = "{$alphaMax}*sin(PI*Y/H)";
                $overlayY = (string) intdiv($H - $gradH, 2);
            }
            $parts[] = "color=c=black:s={$W}x{$gradH}:d=1:r=1,format=rgba,geq=r=0:g=0:b=0:a='{$alphaExpr}'[grad]";
            $parts[] = "{$lastLabel}[grad]overlay=x=0:y={$overlayY}[v1]";
            $lastLabel = '[v1]';
        }

        $inputs = ['-i', $input];
        if ($logo) {
            $inputs[] = '-i';
            $inputs[] = $logo;
            // Escala do logo é proporcional à largura da imagem original.
            $logoW = max(1, (int) round($W * $config['logo_escala']));
            $parts[] = "[1:v]scale={$logoW}:-1[logo]";
            [$x, $y2] = $this->positionCoords($config['logo_posicao']);
            $parts[] = "{$lastLabel}[logo]overlay=x={$x}:y={$y2}[vout]";
            $lastLabel = '[vout]';
        }

        return [
            $this->ffmpegBin, '-y', '-hide_banner', '-loglevel', 'error',
            ...$inputs,
            '-filter_complex', implode(';', $parts),
            '-map', $lastLabel,
            '-frames:v', '1',
            '-q:v', (string) self::JPG_QUALITY,
            '-threads', (string) self::OUT_THREADS,
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
     * Versão IMAGEM do preview: downscale (max 1080px na maior dimensão,
     * preservando aspect) + watermark tiled, output JPG.
     *
     * O preview é sempre menor que o processado clean pra economizar
     * bandwidth público. A watermark PNG é gerada no tamanho EXATO do
     * preview pra evitar distorção do padrão diagonal.
     */
    private function buildWatermarkedPreviewImagem(string $cleanInput, string $previewOutput): void
    {
        $meta = $this->probe($cleanInput);
        $inW = max(1, (int) ($meta['width'] ?? 0));
        $inH = max(1, (int) ($meta['height'] ?? 0));

        // Cap na maior dimensão. Imagem menor que 1080 fica no tamanho original.
        $maxSide = 1080;
        if (max($inW, $inH) > $maxSide) {
            if ($inW >= $inH) {
                $previewW = $maxSide;
                $previewH = max(1, (int) round($maxSide * $inH / $inW));
            } else {
                $previewH = $maxSide;
                $previewW = max(1, (int) round($maxSide * $inW / $inH));
            }
        } else {
            $previewW = $inW;
            $previewH = $inH;
        }

        $watermarkPng = dirname($previewOutput) . DIRECTORY_SEPARATOR . 'watermark-tiled.png';
        $this->generateDiagonalWatermarkPng($watermarkPng, $previewW, $previewH);

        $cmd = [
            $this->ffmpegBin, '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $cleanInput,
            '-i', $watermarkPng,
            '-filter_complex',
                "[0:v]scale={$previewW}:{$previewH}:flags=lanczos[scaled];[scaled][1:v]overlay=0:0[out]",
            '-map', '[out]',
            '-frames:v', '1',
            '-q:v', (string) self::JPG_QUALITY,
            '-threads', '2',
            $previewOutput,
        ];

        $this->runFFmpeg($cmd);
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
            '-threads', '2',
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
     * Cria a PNG do watermark no tamanho exato do preview.
     *
     * Preferência: a LOGO da marca, tiled em diagonal e semi-transparente.
     * Se o arquivo não estiver disponível (deploy sem public/img, GD sem PNG),
     * cai no padrão de texto — preview sem marca d'água nunca é opção.
     */
    private function generateDiagonalWatermarkPng(string $outputPath, int $width, int $height): void
    {
        $logo = $this->resolveWatermarkLogo();
        if ($logo !== null) {
            try {
                $this->desenharLogoTiled($outputPath, $width, $height, $logo);
                return;
            } catch (\Throwable $e) {
                LogProcessamento::warning('watermark.logo_falhou', 'Watermark com logo falhou, usando texto', [
                    'erro' => mb_substr($e->getMessage(), 0, 300),
                ]);
            }
        }

        $this->desenharTextoTiled($outputPath, $width, $height);
    }

    /**
     * Caminho da logo usada no watermark. Configurável (WATERMARK_LOGO) com
     * default na variante clara — traço branco lê melhor sobre a maioria das
     * cenas, e o contorno escuro que aplicamos garante o resto.
     */
    private function resolveWatermarkLogo(): ?string
    {
        $configurado = (string) config('services.watermark.logo', '');
        if ($configurado !== '' && is_file($configurado)) return $configurado;

        $padrao = public_path('img/logo-clara.png');
        return is_file($padrao) ? $padrao : null;
    }

    /**
     * Tiling da logo em diagonal.
     *
     * Cada tile é composto uma vez (sombra escura + logo clara, com opacidade)
     * e depois copiado cru pro canvas. Copiar cru exige que os tiles NÃO se
     * sobreponham — o que combina com o pedido de espaçamento generoso; o passo
     * do grid é sempre maior que o tile por construção.
     */
    private function desenharLogoTiled(string $outputPath, int $width, int $height, string $logoPath): void
    {
        $logo = @imagecreatefrompng($logoPath);
        if (! $logo) {
            throw new RuntimeException("Não foi possível ler a logo: {$logoPath}");
        }

        try {
            // Tile proporcional ao frame: ~30% da largura. Em 540px de preview
            // dá ~162px — legível sem virar tarja.
            $tileW = max(90, (int) round($width * 0.30));
            $tileH = max(1, (int) round($tileW * imagesy($logo) / imagesx($logo)));

            $tile = $this->comporTileLogo($logo, $tileW, $tileH);

            // Rotação de 30°: mesmo ângulo do watermark de texto. Dificulta
            // recorte automático e não deixa a marca alinhada com a cena.
            $transparente = imagecolorallocatealpha($tile, 0, 0, 0, 127);
            $tileRot = imagerotate($tile, 30, $transparente);
            imagealphablending($tileRot, false);
            imagesavealpha($tileRot, true);
            imagedestroy($tile);

            $rotW = imagesx($tileRot);
            $rotH = imagesy($tileRot);

            // Canvas transparente do tamanho do preview
            $canvas = imagecreatetruecolor($width, $height);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

            // "Bem espaçada": folga de ~55% do tile entre uma logo e outra.
            // Como o passo > tile, os copies crus nunca se apagam entre si.
            $stepX = (int) round($rotW * 1.55);
            $stepY = (int) round($rotH * 1.55);

            // Extrapola as bordas pra não deixar canto sem cobertura
            for ($y = -$rotH; $y < $height + $rotH; $y += $stepY) {
                // Linhas alternadas deslocadas — evita "colunas" de logo
                $linha = intdiv($y + $rotH, max(1, $stepY));
                $offset = ($linha % 2 === 0) ? 0 : intdiv($stepX, 2);
                for ($x = -$rotW; $x < $width + $rotW; $x += $stepX) {
                    imagecopy($canvas, $tileRot, $x + $offset, $y, 0, 0, $rotW, $rotH);
                }
            }

            imagedestroy($tileRot);

            $ok = imagepng($canvas, $outputPath);
            imagedestroy($canvas);
            if (! $ok) {
                throw new RuntimeException("Falha ao salvar PNG do watermark em: {$outputPath}");
            }
        } finally {
            if (is_resource($logo) || $logo instanceof \GdImage) imagedestroy($logo);
        }
    }

    /**
     * Monta um tile: logo redimensionada + sombra escura deslocada, ambas com
     * opacidade reduzida.
     *
     * A composição é feita pixel a pixel (src-over manual) porque o blending do
     * GD não resolve alpha sobre canvas transparente — copiar direto comeria a
     * sombra nas áreas vazadas da logo. O tile é pequeno (~160x80), então o
     * custo é irrelevante perto do encode.
     */
    private function comporTileLogo(\GdImage $logo, int $tileW, int $tileH): \GdImage
    {
        $desloc = max(1, (int) round($tileW * 0.012)); // sombra ~2px em 162

        $escalada = imagecreatetruecolor($tileW, $tileH);
        imagealphablending($escalada, false);
        imagesavealpha($escalada, true);
        imagefill($escalada, 0, 0, imagecolorallocatealpha($escalada, 0, 0, 0, 127));
        imagecopyresampled($escalada, $logo, 0, 0, 0, 0, $tileW, $tileH, imagesx($logo), imagesy($logo));

        $tile = imagecreatetruecolor($tileW + $desloc, $tileH + $desloc);
        imagealphablending($tile, false);
        imagesavealpha($tile, true);
        imagefill($tile, 0, 0, imagecolorallocatealpha($tile, 0, 0, 0, 127));

        // 1) Sombra: mesma silhueta pintada de preto, bem fraca — dá contorno
        //    quando a cena atrás é clara e a logo branca sumiria.
        $this->comporSobre($tile, $escalada, $desloc, $desloc, 0.30, true);
        // 2) Logo por cima, "um pouco transparente"
        $this->comporSobre($tile, $escalada, 0, 0, 0.45, false);

        imagedestroy($escalada);

        return $tile;
    }

    /**
     * src-over manual de $src sobre $dst com fator de opacidade.
     * $comoSombra pinta a silhueta de preto preservando o alpha da origem.
     */
    private function comporSobre(\GdImage $dst, \GdImage $src, int $dx, int $dy, float $opacidade, bool $comoSombra): void
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $dstW = imagesx($dst);
        $dstH = imagesy($dst);

        for ($y = 0; $y < $h; $y++) {
            $ty = $y + $dy;
            if ($ty < 0 || $ty >= $dstH) continue;
            for ($x = 0; $x < $w; $x++) {
                $tx = $x + $dx;
                if ($tx < 0 || $tx >= $dstW) continue;

                $rgba = imagecolorat($src, $x, $y);
                $a = ($rgba >> 24) & 0x7F;
                if ($a === 127) continue; // pixel totalmente transparente

                // GD: 0 = opaco, 127 = transparente. Converte pra 0..1.
                $srcA = (1 - $a / 127) * $opacidade;
                if ($srcA <= 0.002) continue;

                if ($comoSombra) {
                    $sr = $sg = $sb = 0;
                } else {
                    $sr = ($rgba >> 16) & 0xFF;
                    $sg = ($rgba >> 8) & 0xFF;
                    $sb = $rgba & 0xFF;
                }

                $destRgba = imagecolorat($dst, $tx, $ty);
                $dstA = 1 - ((($destRgba >> 24) & 0x7F) / 127);
                $dr = ($destRgba >> 16) & 0xFF;
                $dg = ($destRgba >> 8) & 0xFF;
                $db = $destRgba & 0xFF;

                $outA = $srcA + $dstA * (1 - $srcA);
                if ($outA <= 0.002) continue;

                $r = (int) round(($sr * $srcA + $dr * $dstA * (1 - $srcA)) / $outA);
                $g = (int) round(($sg * $srcA + $dg * $dstA * (1 - $srcA)) / $outA);
                $b = (int) round(($sb * $srcA + $db * $dstA * (1 - $srcA)) / $outA);
                $alphaGd = (int) round((1 - $outA) * 127);

                $cor = imagecolorallocatealpha($dst, $r, $g, $b, max(0, min(127, $alphaGd)));
                imagesetpixel($dst, $tx, $ty, $cor);
                imagecolordeallocate($dst, $cor);
            }
        }
    }

    /**
     * Fallback: texto do site tiled em diagonal (comportamento anterior).
     * Halo branco dá contraste em cenas claras E escuras.
     */
    private function desenharTextoTiled(string $outputPath, int $width, int $height): void
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
