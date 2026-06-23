<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Pedido;
use App\Models\Saque;
use App\Models\User;
use App\Models\Video;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $saldoTotal = (float) User::clientes()->sum('saldo_disponivel');
        $vendasMes = (float) Pedido::where('status', 'pago')
            ->whereMonth('pago_em', now()->month)
            ->whereYear('pago_em', now()->year)
            ->sum('total');
        $videosProcessados = Video::where('status', Video::STATUS_CONCLUIDO)->count();
        $pedidosPendentes = Pedido::where('status', 'pendente')->count();
        $saquesPendentes = Saque::where('status', 'solicitado')->count();
        $totalUsuarios = User::clientes()->count();

        $albunsRecentes = Album::with(['user:id,nome', 'evento:id,nome'])
            ->latest()
            ->limit(6)
            ->get();

        return view('pages.admin.dashboard', compact(
            'saldoTotal', 'vendasMes', 'videosProcessados', 'pedidosPendentes',
            'saquesPendentes', 'totalUsuarios', 'albunsRecentes'
        ));
    }
}
