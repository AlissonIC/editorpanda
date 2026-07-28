<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AlbumPublicoController extends Controller
{
    public function show(Album $album): View
    {
        $album->load('evento:id,nome,slug,preco_por_video,status');
        abort_unless($album->status === 'publicado', 404);
        abort_unless($album->evento?->status === 'ativo', 404);

        // Só mostra vídeos ENTREGÁVEIS (concluídos). Vender vídeos processando
        // era risco: se o processamento falhasse, o comprador pagava por nada.
        $videos = $album->videos()
            ->where('status', 'concluido')
            ->select(['id', 'album_id', 'nome', 'status', 'thumbnail_path', 'disk', 'duracao_segundos'])
            ->orderBy('id')
            ->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'nome' => $v->nome,
                'status' => $v->status,
                'processado' => true,
                'duracao' => $this->formatDuration((int) $v->duracao_segundos),
                'thumbnail_url' => $v->thumbnail_path ? route('publico.video.thumb', $v->id) : null,
                'preview_url' => route('publico.video.preview', $v->id),
            ]);

        return view('pages.publico.album', [
            'album' => $album,
            'videos' => $videos,
            'preco' => $album->precoEfetivoPorVideo(),
        ]);
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
        return Storage::disk('local')->response($path, null, [
            'Content-Type' => 'video/mp4',
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
