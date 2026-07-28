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
     * Gera a versão preview (com watermarks tiled) a partir do MP4 limpo já processado.
     *
     * Segunda passagem de encode: `-preset faster` + `-crf 26` (qualidade menor,
     * arquivo mais leve, ~2x mais rápido que o encode principal). Audio é copiado
     * sem re-encode. O visual tem 8 réplicas do nome do site espalhadas + um aviso
     * grande centralizado — projetado pra ser difícil de remover num pós-processamento.
     */
    private function buildWatermarkedPreview(string $cleanInput, string $previewOutput): void
    {
        $font = $this->resolveWatermarkFont();
        $texto = (string) config('services.watermark.texto', 'PANDAVIDEO');

        // Escapa caracteres que o filtergraph do FFmpeg interpreta:
        //   ':'  → separador de opções
        //   '\'  → escape literal
        //   "'"  → delimitador de string
        $escapeText = fn (string $s) => str_replace(
            ["\\", "'", ':', ',', '%'],
            ["\\\\", "\\'", '\\:', '\\,', '\\%'],
            $s,
        );
        $textoEsc = $escapeText($texto);
        $avisoEsc = $escapeText('PREVIEW - PROIBIDA REPRODUCAO');
        // Fonte no filtergraph: barras normais + escape do `:` do drive-letter no Windows
        $fontEsc = str_replace(':', '\\:', str_replace('\\', '/', $font));

        // Filtros: primeiro downscale (540x960 = metade do processado 1080x1920),
        // depois watermarks já dimensionadas pra essa resolução.
        $filtros = [
            // Lanczos: melhor qualidade de downscale, ainda barato pro tamanho.
            'scale=540:960:flags=lanczos',
        ];

        // Watermarks ANIMADAS: 6 faixas em Y fixos, X percorre continuamente.
        // Alternamos direção (LTR ↔ RTL) e usamos velocidades/fases diferentes
        // por faixa. Em qualquer frame há 6+ textos visíveis em posições que
        // MUDAM a cada frame → screen-record captura tudo, mas o pattern não
        // fica no mesmo pixel dois frames seguidos, então mascarar em pós
        // é impraticável (blur/inpainting destruiria o vídeo inteiro).
        //
        // LTR: x = mod(t*V + phase, W+tw) - tw     → entra à esquerda, sai à direita
        // RTL: x = W - mod(t*V + phase, W+tw)      → entra à direita, sai à esquerda
        $faixas = [
            // [y_fracional, velocidade_px_s, phase_px, alpha, direcao]
            [0.10,  90,   0, 0.60, 'ltr'],
            [0.24, 110, 200, 0.60, 'rtl'],
            [0.38,  80,   0, 0.60, 'ltr'],
            [0.62, 100, 350, 0.60, 'rtl'],
            [0.76,  95, 100, 0.60, 'ltr'],
            [0.90,  85, 450, 0.60, 'rtl'],
        ];
        foreach ($faixas as [$yFrac, $vel, $phase, $alpha, $dir]) {
            $xExpr = $dir === 'ltr'
                ? "mod(t*{$vel}+{$phase}\\,w+tw)-tw"
                : "w-mod(t*{$vel}+{$phase}\\,w+tw)";
            $filtros[] = sprintf(
                "drawtext=fontfile='%s':text='%s':fontsize=26:fontcolor=white@%.2f:borderw=2:bordercolor=black@0.70:x='%s':y=h*%.2f-th/2",
                $fontEsc, $textoEsc, $alpha, $xExpr, $yFrac,
            );
        }

        // Aviso central "respirando": alpha oscila entre 0.54 e 0.90 a cada
        // segundo — dificulta detecção automática de watermark estático.
        $filtros[] = sprintf(
            "drawtext=fontfile='%s':text='%s':fontsize=32:fontcolor=white:alpha='0.72+0.18*sin(2*PI*t)':borderw=3:bordercolor=black@0.9:x=(w-tw)/2:y=(h-th)/2",
            $fontEsc, $avisoEsc,
        );

        // Rodapé fixo com branding (reforça origem)
        $filtros[] = 'drawbox=x=0:y=h-56:w=iw:h=36:color=black@0.65:t=fill';
        $filtros[] = sprintf(
            "drawtext=fontfile='%s':text='%s':fontsize=20:fontcolor=white@0.95:x=(w-tw)/2:y=h-46",
            $fontEsc, $textoEsc,
        );

        $vf = implode(',', $filtros);

        $cmd = [
            $this->ffmpegBin, '-y', '-hide_banner', '-loglevel', 'error',
            '-i', $cleanInput,
            '-map', '0:v',
            '-map', '0:a?',              // audio opcional (imagem não tem)
            '-vf', $vf,
            '-c:v', 'libx264',
            '-preset', 'veryfast',       // encode rápido
            '-crf', '28',                // qualidade menor — preview é ruim de propósito
            '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart',
            '-c:a', 'aac',
            '-b:a', '48k',               // audio degradado — impede reaproveitamento
            '-ar', '44100',
            $previewOutput,
        ];

        $this->runFFmpeg($cmd);
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
