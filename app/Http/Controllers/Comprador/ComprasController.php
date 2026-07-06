<?php

namespace App\Http\Controllers\Comprador;

use App\Http\Controllers\Controller;
use App\Models\Pedido;
use App\Models\Video;
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

        $disk = $video->disk ?: 'local';
        if ($disk === 's3') {
            try {
                $url = Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(15), [
                    'ResponseContentDisposition' => 'attachment; filename="' . $video->nome . '"',
                ]);
                return redirect()->away($url);
            } catch (\Throwable) {
                abort(500, 'Falha ao gerar link do S3.');
            }
        }

        return Storage::disk('local')->download($path, $video->nome);
    }
}
