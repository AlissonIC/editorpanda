<?php

namespace App\Http\Controllers\Painel;

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
        return auth()->user()->isAdmin() ? $this->admin() : $this->cliente();
    }

    private function admin(): View
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

        return view('pages.painel.dashboard-admin', compact(
            'saldoTotal', 'vendasMes', 'videosProcessados', 'pedidosPendentes',
            'saquesPendentes', 'totalUsuarios', 'albunsRecentes'
        ));
    }

    private function cliente(): View
    {
        $user = auth()->user();

        $saldo = (float) $user->saldo_disponivel;
        $vendasMes = (float) Pedido::where('user_id', $user->id)
            ->where('status', 'pago')
            ->whereMonth('pago_em', now()->month)
            ->whereYear('pago_em', now()->year)
            ->sum('total');
        $fotosVendidas = (int) Video::where('user_id', $user->id)
            ->whereHas('album.pedidos', fn ($q) => $q->where('status', 'pago'))
            ->count();
        $pedidosPendentes = Pedido::where('user_id', $user->id)
            ->where('status', 'pendente')->count();

        $albunsRecentes = Album::where('user_id', $user->id)
            ->latest()
            ->limit(5)
            ->get(['id', 'nome', 'status']);

        $pedidosRecentes = Pedido::where('user_id', $user->id)
            ->latest()
            ->limit(5)
            ->get(['id', 'comprador_email', 'total', 'status', 'created_at']);

        return view('pages.painel.dashboard-cliente', compact(
            'saldo', 'vendasMes', 'fotosVendidas', 'pedidosPendentes',
            'albunsRecentes', 'pedidosRecentes'
        ));
    }
}
