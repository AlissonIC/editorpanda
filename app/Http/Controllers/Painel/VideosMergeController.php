<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Jobs\MesclarVideosJob;
use App\Models\Album;
use App\Models\Video;
use App\Models\VideoMerge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Merge (concat) para o dono/admin: gera um único mp4 dos vídeos selecionados.
 * Fluxo assíncrono via job.
 */
class VideosMergeController extends Controller
{
    public function store(Request $request, Album $album): JsonResponse
    {
        $this->autorizarAlbum($album);

        $data = $request->validate([
            'video_ids' => ['required', 'array', 'min:2', 'max:200'],
            'video_ids.*' => ['integer'],
        ]);

        // Verifica que todos pertencem ao álbum e estão concluídos
        $videos = Video::where('album_id', $album->id)
            ->whereIn('id', $data['video_ids'])
            ->where('status', Video::STATUS_CONCLUIDO)
            ->whereNotNull('arquivo_processado_path')
            ->get(['id']);

        abort_if($videos->count() < 2, 422, 'É preciso pelo menos 2 vídeos processados.');

        $ids = $videos->pluck('id')->all();
        // Mantém a ordem enviada pelo cliente
        $orderedIds = array_values(array_intersect($data['video_ids'], $ids));

        $merge = VideoMerge::create([
            'user_id' => $album->user_id,
            'comprador_id' => null,
            'pedido_id' => null,
            'video_ids' => $orderedIds,
            'status' => VideoMerge::STATUS_PENDENTE,
            'disk' => \App\Models\Configuracao::storageDisk(),
        ]);

        MesclarVideosJob::dispatch($merge->id);

        return response()->json([
            'merge_id' => $merge->id,
            'slug' => $merge->slug,
            'status' => $merge->status,
            'message' => 'Mescla enfileirada. Você será avisado quando estiver pronta.',
        ], 202);
    }

    public function show(VideoMerge $merge): JsonResponse
    {
        $this->autorizarMerge($merge);

        return response()->json([
            'id' => $merge->id,
            'slug' => $merge->slug,
            'status' => $merge->status,
            'tamanho_bytes' => $merge->tamanho_bytes,
            'erro_msg' => $merge->erro_msg,
            'iniciado_em' => $merge->iniciado_em,
            'concluido_em' => $merge->concluido_em,
            'download_url' => $merge->status === VideoMerge::STATUS_CONCLUIDO
                ? route('painel.videos.merge.download', $merge)
                : null,
        ]);
    }

    public function download(VideoMerge $merge)
    {
        $this->autorizarMerge($merge);
        abort_unless($merge->status === VideoMerge::STATUS_CONCLUIDO && $merge->output_path, 404);

        $nome = $merge->nomeArquivoDownload();
        $disk = $merge->disk ?: 'local';

        if ($disk === 's3') {
            try {
                $url = Storage::disk('s3')->temporaryUrl($merge->output_path, now()->addMinutes(15), [
                    'ResponseContentDisposition' => 'attachment; filename="' . $nome . '"',
                ]);
                return redirect()->away($url);
            } catch (\Throwable) {
                abort(500, 'Falha ao gerar link S3.');
            }
        }

        return Storage::disk('local')->download($merge->output_path, $nome);
    }

    private function autorizarAlbum(Album $album): void
    {
        if (auth()->user()->isAdmin()) return;
        abort_unless($album->user_id === auth()->id(), 403);
    }

    private function autorizarMerge(VideoMerge $merge): void
    {
        if (auth()->user()->isAdmin()) return;
        abort_unless($merge->user_id === auth()->id(), 403);
    }
}
