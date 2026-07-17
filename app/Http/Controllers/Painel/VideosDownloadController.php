<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\ZipStream;

/**
 * Download de vídeos para admin/dono do álbum.
 *
 * Regra de acesso: admin OU user_id do vídeo == auth id.
 * Suporta os dois discos (local, s3):
 *   - S3: redireciona pra presigned URL com Content-Disposition (browser baixa)
 *   - Local: stream via Storage::response()/download()
 *
 * Bulk zip: usa maennchen/zipstream-php — não escreve arquivo temporário nem
 * carrega tudo em memória; a resposta HTTP transmite o zip enquanto lê os
 * arquivos originais em paralelo.
 */
class VideosDownloadController extends Controller
{
    /**
     * Download de UM vídeo, tipo=processado|original.
     */
    public function single(Video $video, string $tipo)
    {
        $this->autorizar($video);
        abort_unless(in_array($tipo, ['processado', 'original'], true), 404);

        $path = $tipo === 'processado'
            ? $video->arquivo_processado_path
            : $video->arquivo_original_path;

        abort_unless($path, 404, 'Arquivo não disponível.');

        $video->loadMissing('album.evento:id,nome');
        $nomeArquivo = $video->nomeArquivoDownload($tipo);
        $disk = $video->disk ?: 'local';

        if ($disk === 's3') {
            try {
                $url = Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(15), [
                    'ResponseContentDisposition' => 'attachment; filename="' . $nomeArquivo . '"',
                ]);
                return redirect()->away($url);
            } catch (\Throwable) {
                abort(500, 'Falha ao gerar link do S3.');
            }
        }

        return Storage::disk('local')->download($path, $nomeArquivo);
    }

    /**
     * Bulk zip: recebe video_ids[] + tipo, verifica que TODOS pertencem ao
     * mesmo álbum (autoriza uma vez), e streama um zip.
     *
     * Uso: POST /painel/albuns/{album}/download-zip { video_ids: [1,2,3], tipo: 'processado' }
     */
    public function zip(Request $request, Album $album): StreamedResponse
    {
        $this->autorizarAlbum($album);

        $data = $request->validate([
            'video_ids' => ['required', 'array', 'min:1', 'max:200'],
            'video_ids.*' => ['integer'],
            'tipo' => ['required', 'in:processado,original'],
        ]);

        $videos = Video::query()
            ->where('album_id', $album->id)
            ->whereIn('id', $data['video_ids'])
            ->with('album.evento:id,nome')
            ->get();

        abort_if($videos->isEmpty(), 404, 'Nenhum vídeo encontrado.');

        $tipo = $data['tipo'];
        $albumSlug = Str::slug($album->evento?->nome ?: $album->nome ?: 'album');
        $nomeZip = $albumSlug . '_' . $tipo . '.zip';

        return new StreamedResponse(function () use ($videos, $tipo) {
            $zip = new ZipStream(sendHttpHeaders: false);

            foreach ($videos as $video) {
                $path = $tipo === 'processado'
                    ? $video->arquivo_processado_path
                    : $video->arquivo_original_path;

                if (! $path) continue;

                $disk = $video->disk ?: 'local';
                $nomeInterno = $video->nomeArquivoDownload($tipo);

                try {
                    $stream = Storage::disk($disk)->readStream($path);
                    if (! $stream) continue;
                    $zip->addFileFromStream($nomeInterno, $stream);
                    if (is_resource($stream)) fclose($stream);
                } catch (\Throwable) {
                    // Vídeo com problema — pula. O zip segue com os outros.
                }
            }

            $zip->finish();
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . $nomeZip . '"',
            'X-Accel-Buffering' => 'no', // Nginx: não bufferar; começa a mandar imediato
        ]);
    }

    private function autorizar(Video $video): void
    {
        if (auth()->user()->isAdmin()) return;
        abort_unless($video->user_id === auth()->id(), 403);
    }

    private function autorizarAlbum(Album $album): void
    {
        if (auth()->user()->isAdmin()) return;
        abort_unless($album->user_id === auth()->id(), 403);
    }
}
