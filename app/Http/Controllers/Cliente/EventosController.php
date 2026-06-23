<?php

namespace App\Http\Controllers\Cliente;

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
        return view('pages.cliente.eventos');
    }

    public function data(Request $request): JsonResponse
    {
        $query = Evento::query()
            ->withCount('albuns')
            ->where('user_id', auth()->id())
            ->select(['id', 'nome', 'localizacao_cidade', 'localizacao_estado', 'data', 'status']);

        return DataTables::eloquent($query)
            ->editColumn('data', fn ($e) => $e->data?->format('d/m/Y') ?? '—')
            ->editColumn('status', fn ($e) => '<span class="status-badge ' . $e->status . '">' . ucfirst($e->status) . '</span>')
            ->addColumn('localizacao', fn ($e) => trim(($e->localizacao_cidade ?: '') . ($e->localizacao_estado ? ' / ' . $e->localizacao_estado : '')) ?: '—')
            ->addColumn('acoes',
                fn ($e) => '<button class="btn btn-sm btn-outline-primary me-1 js-edit" data-id="' . $e->id . '"><i class="bi bi-pencil"></i></button>'
                    . '<button class="btn btn-sm btn-outline-danger js-delete" data-id="' . $e->id . '"><i class="bi bi-trash"></i></button>'
            )
            ->rawColumns(['status', 'acoes'])
            ->make(true);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'localizacao_cidade' => ['nullable', 'string', 'max:120'],
            'localizacao_estado' => ['nullable', 'string', 'max:100'],
            'data' => ['nullable', 'date'],
            'status' => ['required', 'in:ativo,inativo'],
        ]);

        $evento = auth()->user()->eventos()->create($data);

        return response()->json(['evento' => $evento, 'message' => 'Evento criado.'], 201);
    }

    public function show(Evento $evento): JsonResponse
    {
        $this->authorizeDono($evento);

        return response()->json($evento);
    }

    public function update(Request $request, Evento $evento): JsonResponse
    {
        $this->authorizeDono($evento);

        $data = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'localizacao_cidade' => ['nullable', 'string', 'max:120'],
            'localizacao_estado' => ['nullable', 'string', 'max:100'],
            'data' => ['nullable', 'date'],
            'status' => ['required', 'in:ativo,inativo'],
        ]);

        $evento->update($data);

        return response()->json(['evento' => $evento, 'message' => 'Evento atualizado.']);
    }

    public function destroy(Evento $evento): JsonResponse
    {
        $this->authorizeDono($evento);
        $evento->delete();

        return response()->json(['message' => 'Evento removido.']);
    }

    private function authorizeDono(Evento $evento): void
    {
        abort_unless($evento->user_id === auth()->id(), 403);
    }
}
