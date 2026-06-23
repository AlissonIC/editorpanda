<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class EventosController extends Controller
{
    public function index(): View
    {
        return view('pages.admin.eventos');
    }

    public function data(Request $request): JsonResponse
    {
        $query = Evento::query()
            ->withCount('albuns')
            ->with('user:id,nome')
            ->select(['id', 'user_id', 'nome', 'localizacao_cidade', 'localizacao_estado', 'data', 'status']);

        return DataTables::eloquent($query)
            ->addColumn('cliente', fn ($e) => $e->user?->nome ?? '—')
            ->editColumn('data', fn ($e) => $e->data?->format('d/m/Y') ?? '—')
            ->editColumn('status', fn ($e) => '<span class="status-badge ' . $e->status . '">' . ucfirst($e->status) . '</span>')
            ->addColumn('localizacao', fn ($e) => trim(($e->localizacao_cidade ?: '') . ($e->localizacao_estado ? ' / ' . $e->localizacao_estado : '')) ?: '—')
            ->addColumn('acoes', fn ($e) => '<button class="btn btn-sm btn-outline-danger js-delete" data-id="' . $e->id . '"><i class="bi bi-trash"></i></button>')
            ->rawColumns(['status', 'acoes'])
            ->make(true);
    }

    public function destroy(Evento $evento): JsonResponse
    {
        $evento->delete();

        return response()->json(['message' => 'Evento removido.']);
    }
}
