<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Despesa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

/**
 * CRUD de despesas — só admin. Acessível pela aba "Gastos" do /painel/financeiro.
 */
class DespesasController extends Controller
{
    public function data(Request $request): JsonResponse
    {
        $query = Despesa::query()->with('criador:id,nome');

        $filters = $request->input('filters', []);
        if (! empty($filters['recorrente'])) {
            $query->where('recorrente', $filters['recorrente'] === 'sim');
        }
        if (! empty($filters['categoria'])) {
            $query->where('categoria', $filters['categoria']);
        }
        // Filtro por período — despesas recorrentes têm data_gasto = criação/última;
        // pra elas ainda faz sentido filtrar por quando foram registradas.
        $dias = (int) ($filters['periodo_dias'] ?? 0);
        if ($dias > 0) {
            $query->where('data_gasto', '>=', now()->subDays($dias));
        }

        return DataTables::eloquent($query)
            ->editColumn('data_gasto', fn ($d) => $d->data_gasto?->format('d/m/Y') ?? '—')
            ->editColumn('valor', fn ($d) => 'R$ ' . number_format((float) $d->valor, 2, ',', '.'))
            ->addColumn('recorrencia', function ($d) {
                if (! $d->recorrente) return '<span class="badge bg-secondary-subtle text-secondary-emphasis">Único</span>';
                $rot = ucfirst($d->frequencia ?? '—');
                return '<span class="badge bg-info-subtle text-info-emphasis">Recorrente · ' . e($rot) . '</span>';
            })
            ->editColumn('categoria', fn ($d) => $d->categoria ? e($d->categoria) : '—')
            ->addColumn('acoes', function ($d) {
                return '<button class="btn btn-sm btn-outline-primary me-1 js-despesa-edit" data-id="' . $d->id . '" title="Editar"><i class="bi bi-pencil"></i></button>'
                    . '<button class="btn btn-sm btn-outline-danger js-despesa-del" data-id="' . $d->id . '" title="Remover"><i class="bi bi-trash"></i></button>';
            })
            ->rawColumns(['recorrencia', 'acoes'])
            ->make(true);
    }

    public function show(Despesa $despesa): JsonResponse
    {
        return response()->json($despesa);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validar($request);
        $data['criado_por'] = auth()->id();
        $despesa = Despesa::create($data);
        return response()->json(['despesa' => $despesa, 'message' => 'Despesa cadastrada.'], 201);
    }

    public function update(Request $request, Despesa $despesa): JsonResponse
    {
        $data = $this->validar($request);
        $despesa->update($data);
        return response()->json(['despesa' => $despesa, 'message' => 'Despesa atualizada.']);
    }

    public function destroy(Despesa $despesa): JsonResponse
    {
        $despesa->delete();
        return response()->json(['message' => 'Despesa removida.']);
    }

    private function validar(Request $request): array
    {
        $data = $request->validate([
            'descricao' => ['required', 'string', 'max:255'],
            'valor' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'categoria' => ['nullable', 'string', 'max:60'],
            'data_gasto' => ['required', 'date'],
            'recorrente' => ['nullable', 'boolean'],
            'frequencia' => ['nullable', 'in:' . implode(',', Despesa::FREQUENCIAS)],
            'observacao' => ['nullable', 'string', 'max:2000'],
        ]);
        // Se não é recorrente, força frequencia = null (mantém DB consistente).
        $data['recorrente'] = (bool) ($data['recorrente'] ?? false);
        if (! $data['recorrente']) $data['frequencia'] = null;
        // Recorrente exige frequencia
        if ($data['recorrente'] && empty($data['frequencia'])) {
            abort(response()->json([
                'message' => 'Escolha a frequência da despesa recorrente.',
                'errors' => ['frequencia' => ['Obrigatório para despesas recorrentes.']],
            ], 422));
        }
        return $data;
    }
}
