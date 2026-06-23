<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Album;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class AlbunsController extends Controller
{
    public function index(): View
    {
        return view('pages.admin.albuns');
    }

    public function data(Request $request): JsonResponse
    {
        $query = Album::query()
            ->withCount('videos')
            ->with(['user:id,nome', 'evento:id,nome,localizacao_cidade,localizacao_estado'])
            ->select(['id', 'user_id', 'evento_id', 'nome', 'subtitulo', 'status', 'created_at']);

        return DataTables::eloquent($query)
            ->addColumn('cliente', fn ($a) => $a->user?->nome ?? '—')
            ->addColumn('evento', fn ($a) => $a->evento?->nome ?? '—')
            ->editColumn('status', fn ($a) => '<span class="status-badge ' . $a->status . '">' . ucfirst($a->status) . '</span>')
            ->editColumn('created_at', fn ($a) => $a->created_at?->format('d/m/Y'))
            ->addColumn('acoes', fn ($a) => '<button class="btn btn-sm btn-outline-danger js-delete" data-id="' . $a->id . '"><i class="bi bi-trash"></i></button>')
            ->rawColumns(['status', 'acoes'])
            ->make(true);
    }

    public function destroy(Album $album): JsonResponse
    {
        $album->delete();

        return response()->json(['message' => 'Álbum removido.']);
    }
}
