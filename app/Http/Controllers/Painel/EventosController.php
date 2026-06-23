<?php

namespace App\Http\Controllers\Painel;

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
        return view('pages.painel.eventos');
    }

    public function data(Request $request): JsonResponse
    {
        $query = Evento::query()
            ->select(['id', 'user_id', 'nome', 'localizacao_cidade', 'localizacao_estado', 'data', 'status'])
            ->withCount('albuns')
            ->with('user:id,nome');

        if (! auth()->user()->isAdmin()) {
            $query->where('user_id', auth()->id());
        }

        return DataTables::eloquent($query)
            ->addColumn('cliente', fn ($e) => $e->user?->nome ?? '—')
            ->addColumn('localizacao', fn ($e) => trim(($e->localizacao_cidade ?: '') . ($e->localizacao_estado ? ' / ' . $e->localizacao_estado : '')) ?: '—')
            ->editColumn('data', fn ($e) => $e->data?->format('d/m/Y') ?? '—')
            ->editColumn('status', fn ($e) => '<span class="status-badge ' . $e->status . '">' . ucfirst($e->status) . '</span>')
            ->addColumn('acoes', function ($e) {
                if (auth()->user()->isAdmin()) {
                    return '<button class="btn btn-sm btn-outline-danger js-delete" data-id="' . $e->id . '"><i class="bi bi-trash"></i></button>';
                }
                return '<button class="btn btn-sm btn-outline-primary me-1 js-edit" data-id="' . $e->id . '"><i class="bi bi-pencil"></i></button>'
                    . '<button class="btn btn-sm btn-outline-danger js-delete" data-id="' . $e->id . '"><i class="bi bi-trash"></i></button>';
            })
            ->rawColumns(['status', 'acoes'])
            ->make(true);
    }

    public function store(Request $request): JsonResponse
    {
        abort_if(auth()->user()->isAdmin(), 403, 'Admin não cria eventos.');

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
        $this->authorize($evento);

        return response()->json($evento);
    }

    public function update(Request $request, Evento $evento): JsonResponse
    {
        $this->authorize($evento);
        abort_if(auth()->user()->isAdmin(), 403, 'Admin não edita eventos diretamente.');

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
        $this->authorize($evento);
        $evento->delete();

        return response()->json(['message' => 'Evento removido.']);
    }

    private function authorize(Evento $evento): void
    {
        if (auth()->user()->isAdmin()) {
            return;
        }
        abort_unless($evento->user_id === auth()->id(), 403);
    }
}
