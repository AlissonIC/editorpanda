<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pedido;
use App\Models\Saque;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class FinanceiroController extends Controller
{
    public function index(): View
    {
        $totalPago = (float) Pedido::where('status', 'pago')->sum('total');
        $totalSaquesPagos = (float) Saque::where('status', 'pago')->sum('valor');
        $totalSaquesPendentes = (float) Saque::where('status', 'solicitado')->sum('valor');

        return view('pages.painel.financeiro', compact('totalPago', 'totalSaquesPagos', 'totalSaquesPendentes'));
    }

    public function vendasData(Request $request): JsonResponse
    {
        $query = Pedido::query()->with(['album:id,nome', 'user:id,nome'])
            ->select(['id', 'album_id', 'user_id', 'comprador_email', 'total', 'status', 'created_at']);

        $filters = $request->input('filters', []);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return DataTables::eloquent($query)
            ->addColumn('album', fn ($p) => $p->album?->nome ?? '—')
            ->addColumn('cliente', fn ($p) => $p->user?->nome ?? '—')
            ->editColumn('total', fn ($p) => 'R$ ' . number_format((float) $p->total, 2, ',', '.'))
            ->editColumn('status', fn ($p) => '<span class="status-badge ' . $p->status . '">' . ucfirst($p->status) . '</span>')
            ->editColumn('created_at', fn ($p) => $p->created_at?->format('d/m/Y H:i'))
            ->rawColumns(['status'])
            ->make(true);
    }

    public function saquesData(Request $request): JsonResponse
    {
        $query = Saque::query()->with('user:id,nome')
            ->select(['id', 'user_id', 'valor', 'status', 'solicitado_em', 'pago_em']);

        $filters = $request->input('filters', []);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return DataTables::eloquent($query)
            ->addColumn('cliente', fn ($s) => $s->user?->nome ?? '—')
            ->editColumn('valor', fn ($s) => 'R$ ' . number_format((float) $s->valor, 2, ',', '.'))
            ->editColumn('status', fn ($s) => '<span class="status-badge ' . $s->status . '">' . ucfirst($s->status) . '</span>')
            ->editColumn('solicitado_em', fn ($s) => $s->solicitado_em?->format('d/m/Y H:i'))
            ->addColumn('acoes', function ($s) {
                if ($s->status !== 'solicitado') return '—';
                return '<button class="btn btn-sm btn-success me-1 js-aprovar" data-id="' . $s->id . '">Aprovar</button>'
                    . '<button class="btn btn-sm btn-outline-danger js-recusar" data-id="' . $s->id . '">Recusar</button>';
            })
            ->rawColumns(['status', 'acoes'])
            ->make(true);
    }

    /**
     * Aprovar saque: o saldo já foi reservado (subtraído de saldo_disponivel)
     * no momento da solicitação. Aqui só marcamos como pago.
     */
    public function aprovarSaque(Saque $saque): JsonResponse
    {
        return DB::transaction(function () use ($saque) {
            $fresh = Saque::whereKey($saque->id)->lockForUpdate()->first();
            if ($fresh->status !== 'solicitado') {
                return response()->json(['message' => 'Saque já processado.'], 422);
            }
            $fresh->update(['status' => 'pago', 'pago_em' => now()]);
            return response()->json(['message' => 'Saque aprovado.']);
        });
    }

    /**
     * Recusar saque: devolve o valor ao saldo do vendedor (reversal da reserva
     * feita na solicitação).
     */
    public function recusarSaque(Saque $saque): JsonResponse
    {
        return DB::transaction(function () use ($saque) {
            $fresh = Saque::whereKey($saque->id)->lockForUpdate()->first();
            if ($fresh->status !== 'solicitado') {
                return response()->json(['message' => 'Saque já processado.'], 422);
            }

            $valorCents = (int) round((float) $fresh->valor * 100);
            User::whereKey($fresh->user_id)->lockForUpdate()->first();
            DB::table('users')->where('id', $fresh->user_id)->update([
                'saldo_disponivel' => DB::raw("saldo_disponivel + ({$valorCents} / 100)"),
            ]);

            $fresh->update(['status' => 'recusado']);
            return response()->json(['message' => 'Saque recusado e valor devolvido ao saldo.']);
        });
    }
}
