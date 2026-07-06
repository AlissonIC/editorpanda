<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessarVideoJob;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class ProcessamentoController extends Controller
{
    public function index(): View
    {
        $contadores = [
            'pendente' => Video::where('status', Video::STATUS_PENDENTE)->count(),
            'processando' => Video::where('status', Video::STATUS_PROCESSANDO)->count(),
            'concluido' => Video::where('status', Video::STATUS_CONCLUIDO)->count(),
            'falhou' => Video::where('status', Video::STATUS_FALHOU)->count(),
        ];

        return view('pages.painel.processamento', compact('contadores'));
    }

    public function data(Request $request): JsonResponse
    {
        $query = Video::query()
            ->with(['album:id,nome', 'user:id,nome'])
            ->select(['id', 'album_id', 'user_id', 'nome', 'status', 'erro_msg', 'tamanho_bytes', 'created_at', 'processado_em']);

        $filters = $request->input('filters', []);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return DataTables::eloquent($query)
            ->addColumn('album', fn ($v) => $v->album?->nome ?? '—')
            ->addColumn('cliente', fn ($v) => $v->user?->nome ?? '—')
            ->editColumn('tamanho_bytes', fn ($v) => $v->tamanho_bytes ? round($v->tamanho_bytes / 1048576, 2) . ' MB' : '—')
            ->editColumn('status', fn ($v) => '<span class="status-badge ' . $v->status . '">' . ucfirst($v->status) . '</span>')
            ->editColumn('created_at', fn ($v) => $v->created_at?->format('d/m/Y H:i'))
            ->addColumn('acoes', function ($v) {
                if (in_array($v->status, [Video::STATUS_FALHOU, Video::STATUS_PENDENTE], true)) {
                    return '<button class="btn btn-sm btn-outline-primary js-reprocessar" data-id="' . $v->id . '"><i class="bi bi-arrow-clockwise"></i> Reprocessar</button>';
                }
                return '—';
            })
            ->rawColumns(['status', 'acoes'])
            ->make(true);
    }

    public function reprocessar(Video $video): JsonResponse
    {
        $video->update(['status' => Video::STATUS_PENDENTE, 'erro_msg' => null]);
        ProcessarVideoJob::dispatch($video->id);

        return response()->json(['message' => 'Vídeo enviado para reprocessamento.']);
    }
}
