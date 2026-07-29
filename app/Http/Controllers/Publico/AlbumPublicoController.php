<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AlbumPublicoController extends Controller
{
    // Página inicial (SSR) e cada chunk de paginação carregam este tamanho.
    // 24 = múltiplo de 2/3/4/6 → grid fica cheia em qualquer breakpoint.
    private const PAGE_SIZE = 24;

    public function show(Album $album): View
    {
        // Carregamos os campos que o partial hero-evento precisa (capa, logo,
        // localização, data) sem trazer a tabela toda.
        $album->load('evento:id,nome,slug,preco_por_video,status,localizacao_cidade,localizacao_estado,data,capa_path,capa_disk,logo_path,logo_disk');
        abort_unless($album->status === 'publicado', 404);
        abort_unless($album->evento?->status === 'ativo', 404);

        $baseQuery = $album->videos()->where('status', 'concluido');
        $total = (clone $baseQuery)->count();

        // SSR carrega só a primeira página; o restante é infinite scroll.
        // Isso evita renderizar 500 cards em álbuns grandes (HTML enorme,
        // paint travado, dezenas de MB de thumbnails baixadas de cara).
        $videosDb = $baseQuery
            ->select(['id', 'album_id', 'nome', 'status', 'thumbnail_path', 'disk', 'duracao_segundos', 'arquivo_preview_path'])
            ->orderBy('id')
            ->limit(self::PAGE_SIZE)
            ->get();

        $videos = $videosDb->map(fn ($v) => $this->mapVideoParaCard($v));
        $proxCursor = $videosDb->count() < self::PAGE_SIZE ? null : (int) $videosDb->last()->id;

        return view('pages.publico.album', [
            'album' => $album,
            'videos' => $videos,
            'preco' => $album->precoEfetivoPorVideo(),
            'videosTotal' => $total,
            'proxCursor' => $proxCursor,
        ]);
    }

    /**
     * Endpoint JSON de paginação — retorna a próxima página de vídeos.
     * Cursor-based (por ID) — mais eficiente que offset em álbuns grandes
     * e imune a duplicações/gaps se novos vídeos forem publicados durante
     * a navegação do cliente.
     */
    public function listarVideos(Album $album, Request $request): JsonResponse
    {
        abort_unless($album->status === 'publicado', 404);
        $album->loadMissing('evento:id,status');
        abort_unless($album->evento?->status === 'ativo', 404);

        $after = (int) $request->query('after', 0);
        $limit = min((int) $request->query('limit', self::PAGE_SIZE), 100);

        $videosDb = $album->videos()
            ->where('status', 'concluido')
            ->when($after > 0, fn ($q) => $q->where('id', '>', $after))
            ->select(['id', 'album_id', 'nome', 'status', 'thumbnail_path', 'disk', 'duracao_segundos', 'arquivo_preview_path'])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'videos' => $videosDb->map(fn ($v) => $this->mapVideoParaCard($v))->values(),
            'next_cursor' => $videosDb->count() < $limit ? null : (int) $videosDb->last()->id,
        ]);
    }

    private function mapVideoParaCard(Video $v): array
    {
        return [
            'id' => $v->id,
            'nome' => $v->nome,
            'status' => $v->status,
            'processado' => true,
            // is_imagem baseado na EXTENSÃO REAL do arquivo de preview,
            // não no nome — legados (imagens processadas como mp4 antes) devem
            // continuar rodando como vídeo até serem reprocessados.
            'is_imagem' => str_ends_with(strtolower((string) $v->arquivo_preview_path), '.jpg')
                        || str_ends_with(strtolower((string) $v->arquivo_preview_path), '.jpeg'),
            'duracao' => $this->formatDuration((int) $v->duracao_segundos),
            'thumbnail_url' => $v->thumbnail_path ? route('publico.video.thumb', $v->id) : null,
            'preview_url' => route('publico.video.preview', $v->id),
        ];
    }

    /**
     * Serve thumbnail publicamente para vídeos de álbuns publicados de eventos ativos.
     * Não vaza nada: só retorna 200 se o vídeo é público via seu álbum.
     */
    public function servirThumbnail(Video $video)
    {
        // Só libera se o álbum está publicado e evento ativo
        $album = $video->album()->with('evento')->first();
        abort_unless($album && $album->status === 'publicado', 404);
        abort_unless($album->evento && $album->evento->status === 'ativo', 404);
        abort_unless($video->thumbnail_path, 404);

        $disco = $video->disk ?: 'local';
        if ($disco === 's3') {
            try {
                $url = Storage::disk('s3')->temporaryUrl($video->thumbnail_path, now()->addMinutes(15));
                return redirect()->away($url);
            } catch (\Throwable) {
                abort(500);
            }
        }
        return Storage::disk('local')->response($video->thumbnail_path);
    }

    /**
     * Stream do vídeo de PREVIEW para o público (na página /a/{slug}).
     * Serve o arquivo_preview_path (watermarks tiled + aviso de reprodução).
     *
     * Fallback: vídeos processados antes desta feature não têm preview — nesse
     * caso caímos no arquivo_processado_path (pior que ter preview, mas evita
     * quebrar álbuns antigos). Depois de reprocessar, o preview é gerado.
     */
    public function servirPreview(Video $video)
    {
        $album = $video->album()->with('evento')->first();
        abort_unless($album && $album->status === 'publicado', 404);
        abort_unless($album->evento && $album->evento->status === 'ativo', 404);
        abort_unless($video->status === 'concluido', 404);

        $path = $video->arquivo_preview_path ?: $video->arquivo_processado_path;
        abort_unless($path, 404);

        $disco = $video->disk ?: 'local';
        if ($disco === 's3') {
            try {
                $url = Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(15));
                return redirect()->away($url);
            } catch (\Throwable) {
                abort(500);
            }
        }
        // Content-Type depende da extensão: imagens processadas saem como .jpg,
        // vídeos como .mp4. Envio errado quebra o <img>/<video> no cliente.
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $contentType = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'video/mp4',
        };
        return Storage::disk('local')->response($path, null, [
            'Content-Type' => $contentType,
        ]);
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) return '—';
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return sprintf('%d:%02d', $m, $s);
    }
}
