<?php

namespace App\Http\Controllers\Cliente;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Pedido;
use App\Models\Video;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
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

        return view('pages.cliente.dashboard', compact(
            'saldo', 'vendasMes', 'fotosVendidas', 'pedidosPendentes',
            'albunsRecentes', 'pedidosRecentes'
        ));
    }
}
