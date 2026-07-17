<?php

namespace App\Http\Controllers\Comprador;

use App\Http\Controllers\Controller;
use App\Jobs\MesclarVideosJob;
use App\Models\Configuracao;
use App\Models\Pedido;
use App\Models\Video;
use App\Models\VideoMerge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ComprasController extends Controller
{
    public function index(): View
    {
        $comprador = auth('comprador')->user();
        $pedidos = $comprador->pedidos()
            ->with(['album:id,nome,slug', 'itens.video:id,nome,status,thumbnail_path,disk,arquivo_processado_path,duracao_segundos'])
            ->orderByDesc('id')
            ->get();

        return view('pages.publico.minhas-compras', [
            'pedidos' => $pedidos,
        ]);
    }

    /**
     * Stream/download de um vídeo comprado.
     *   - S3: redirect para presigned URL de 15 min
     *   - local: stream via Storage::response()
     */
    public function baixarVideo(Video $video)
    {
        $comprador = auth('comprador')->user();

        // Só libera se o comprador tem um pedido pago com este vídeo
        $temAcesso = Pedido::where('comprador_id', $comprador->id)
            ->where('status', 'pago')
            ->whereHas('itens', fn ($q) => $q->where('video_id', $video->id))
            ->exists();

        abort_unless($temAcesso, 404);
        abort_unless($video->status === 'concluido', 404, 'Vídeo ainda em processamento.');

        $path = $video->arquivo_processado_path ?: $video->arquivo_original_path;
        abort_unless($path, 404);

        $video->loadMissing('album.evento:id,nome');
        // Sempre entrega o processado (com watermark do vendedor). Nome padronizado.
        $tipo = $video->arquivo_processado_path ? 'processado' : 'original';
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
     * Solicita mescla de N vídeos comprados em um único arquivo. Assíncrono.
     */
    public function solicitarMerge(Request $request, Pedido $pedido): JsonResponse
    {
        $comprador = auth('comprador')->user();
        abort_unless($pedido->comprador_id === $comprador->id, 403);
        abort_unless($pedido->status === 'pago', 422, 'Pedido não pago.');

        $data = $request->validate([
            'video_ids' => ['required', 'array', 'min:2'],
            'video_ids.*' => ['integer'],
        ]);

        // Todos os video_ids têm que estar nos itens desse pedido
        $itensIds = $pedido->itens()->pluck('video_id')->all();
        $selecionados = array_intersect($data['video_ids'], $itensIds);
        abort_if(count($selecionados) < 2, 422, 'Selecione pelo menos 2 vídeos do pedido.');

        // Só concluídos
        $videos = Video::whereIn('id', $selecionados)
            ->where('status', Video::STATUS_CONCLUIDO)
            ->whereNotNull('arquivo_processado_path')
            ->pluck('id')->all();
        abort_if(count($videos) < 2, 422, 'Precisa de 2+ vídeos concluídos.');

        $orderedIds = array_values(array_intersect($data['video_ids'], $videos));

        $merge = VideoMerge::create([
            'comprador_id' => $comprador->id,
            'pedido_id' => $pedido->id,
            'user_id' => null,
            'video_ids' => $orderedIds,
            'status' => VideoMerge::STATUS_PENDENTE,
            'disk' => Configuracao::storageDisk(),
        ]);

        MesclarVideosJob::dispatch($merge->id);

        return response()->json([
            'merge_id' => $merge->id,
            'slug' => $merge->slug,
            'status' => $merge->status,
            'message' => 'Mescla enfileirada — acompanhe em "Minhas compras".',
        ], 202);
    }

    public function mergeStatus(VideoMerge $merge): JsonResponse
    {
        $comprador = auth('comprador')->user();
        abort_unless($merge->comprador_id === $comprador->id, 403);

        return response()->json([
            'id' => $merge->id,
            'slug' => $merge->slug,
            'status' => $merge->status,
            'erro_msg' => $merge->erro_msg,
            'tamanho_bytes' => $merge->tamanho_bytes,
            'download_url' => $merge->status === VideoMerge::STATUS_CONCLUIDO
                ? route('publico.merge.download', $merge)
                : null,
        ]);
    }

    public function baixarMerge(VideoMerge $merge)
    {
        $comprador = auth('comprador')->user();
        abort_unless($merge->comprador_id === $comprador->id, 403);
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
                abort(500);
            }
        }

        return Storage::disk('local')->download($merge->output_path, $nome);
    }
}
