<?php

namespace App\Http\Controllers\Cliente;

use App\Http\Controllers\Controller;
use App\Models\Pedido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class PedidosController extends Controller
{
    public function index(): View
    {
        return view('pages.cliente.pedidos');
    }

    public function data(Request $request): JsonResponse
    {
        $query = Pedido::query()
            ->with('album:id,nome')
            ->where('user_id', auth()->id())
            ->select(['id', 'album_id', 'comprador_nome', 'comprador_email', 'total', 'status', 'created_at']);

        return DataTables::eloquent($query)
            ->addColumn('album', fn ($p) => $p->album?->nome ?? '—')
            ->editColumn('total', fn ($p) => 'R$ ' . number_format((float) $p->total, 2, ',', '.'))
            ->editColumn('status', fn ($p) => '<span class="status-badge ' . $p->status . '">' . ucfirst($p->status) . '</span>')
            ->editColumn('created_at', fn ($p) => $p->created_at?->format('d/m/Y H:i'))
            ->rawColumns(['status'])
            ->make(true);
    }
}
