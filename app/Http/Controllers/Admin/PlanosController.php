<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plano;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class PlanosController extends Controller
{
    public function index(): View
    {
        return view('pages.painel.planos');
    }

    public function data(Request $request): JsonResponse
    {
        $query = Plano::query()->select([
            'id', 'nome', 'preco', 'armazenamento_gb', 'taxa_por_venda',
            'popular', 'ativo', 'ordem', 'created_at',
        ])->withCount('usuarios');

        $filters = $request->input('filters', []);
        if (isset($filters['ativo']) && $filters['ativo'] !== '') {
            $query->where('ativo', (int) $filters['ativo']);
        }
        if (! empty($filters['popular'])) {
            $query->where('popular', 1);
        }

        return DataTables::eloquent($query)
            ->editColumn('preco', fn ($p) => 'R$ ' . number_format((float) $p->preco, 2, ',', '.'))
            ->editColumn('taxa_por_venda', fn ($p) => number_format((float) $p->taxa_por_venda, 2, ',', '.') . '%')
            ->editColumn('armazenamento_gb', fn ($p) => $p->armazenamento_gb . ' GB')
            ->editColumn('ativo', fn ($p) => $p->ativo
                ? '<span class="status-badge ativo">Ativo</span>'
                : '<span class="status-badge inativo">Inativo</span>')
            ->editColumn('popular', fn ($p) => $p->popular
                ? '<span class="status-badge publicado">Popular</span>'
                : '—')
            ->editColumn('created_at', fn ($p) => $p->created_at?->format('d/m/Y'))
            ->addColumn('usuarios', fn ($p) => $p->usuarios_count)
            ->addColumn('acoes', function ($p) {
                return '<button class="btn btn-sm btn-outline-primary me-1 js-edit" data-id="' . $p->id . '"><i class="bi bi-pencil"></i></button>'
                    . '<button class="btn btn-sm btn-outline-danger js-delete" data-id="' . $p->id . '"><i class="bi bi-trash"></i></button>';
            })
            ->rawColumns(['ativo', 'popular', 'acoes'])
            ->make(true);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);
        $plano = Plano::create($data);

        return response()->json(['plano' => $plano, 'message' => 'Plano criado.'], 201);
    }

    public function show(Plano $plano): JsonResponse
    {
        return response()->json($plano);
    }

    public function update(Request $request, Plano $plano): JsonResponse
    {
        $data = $this->validateData($request, $plano->id);
        $plano->update($data);

        return response()->json(['plano' => $plano, 'message' => 'Plano atualizado.']);
    }

    public function destroy(Plano $plano): JsonResponse
    {
        if ($plano->usuarios()->exists()) {
            return response()->json([
                'message' => 'Não é possível remover: existem usuários vinculados a este plano.',
            ], 422);
        }

        $plano->delete();

        return response()->json(['message' => 'Plano removido.']);
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'nome' => ['required', 'string', 'max:100'],
            'descricao' => ['nullable', 'string', 'max:255'],
            'preco' => ['required', 'numeric', 'min:0'],
            'armazenamento_gb' => ['required', 'integer', 'min:1'],
            'taxa_por_venda' => ['required', 'numeric', 'min:0', 'max:100'],
            'popular' => ['nullable', 'boolean'],
            'ativo' => ['nullable', 'boolean'],
            'ordem' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
