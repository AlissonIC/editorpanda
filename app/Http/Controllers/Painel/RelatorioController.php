<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Pedido;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class RelatorioController extends Controller
{
    public function index(): View
    {
        return view('pages.painel.relatorio');
    }

    public function vendasPorMes(): JsonResponse
    {
        $userId = auth()->id();

        $rows = Pedido::selectRaw('DATE_FORMAT(pago_em, "%Y-%m") as mes, SUM(total) as total')
            ->where('user_id', $userId)
            ->where('status', 'pago')
            ->whereNotNull('pago_em')
            ->where('pago_em', '>=', now()->subMonths(11)->startOfMonth())
            ->groupBy('mes')
            ->orderBy('mes')
            ->get();

        return response()->json([
            'labels' => $rows->pluck('mes'),
            'totais' => $rows->pluck('total')->map(fn ($v) => (float) $v),
        ]);
    }

    public function topAlbuns(): JsonResponse
    {
        $userId = auth()->id();

        $rows = Album::select('albuns.id', 'albuns.nome')
            ->selectRaw('COALESCE(SUM(pedidos.total),0) as total_vendido')
            ->leftJoin('pedidos', function ($j) {
                $j->on('pedidos.album_id', '=', 'albuns.id')->where('pedidos.status', 'pago');
            })
            ->where('albuns.user_id', $userId)
            ->groupBy('albuns.id', 'albuns.nome')
            ->orderByDesc('total_vendido')
            ->limit(5)
            ->get();

        return response()->json([
            'labels' => $rows->pluck('nome'),
            'totais' => $rows->pluck('total_vendido')->map(fn ($v) => (float) $v),
        ]);
    }
}
