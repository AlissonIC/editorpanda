<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Pedido;
use App\Services\PedidoPagamentoService;
use App\Support\MercadoPagoStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class PedidosController extends Controller
{
    public function index(): View
    {
        return view('pages.painel.pedidos');
    }

    public function data(Request $request): JsonResponse
    {
        $query = Pedido::query()
            ->with(['album:id,nome', 'user:id,nome'])
            ->select([
                'id', 'album_id', 'user_id', 'comprador_nome', 'comprador_email',
                'total', 'status', 'payment_method', 'created_at',
            ]);

        if (! auth()->user()->isAdmin()) {
            $query->where('user_id', auth()->id());
        }

        $filters = $request->input('filters', []);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return DataTables::eloquent($query)
            ->addColumn('album', fn ($p) => $p->album?->nome ?? '—')
            ->addColumn('cliente', fn ($p) => $p->user?->nome ?? '—')
            ->addColumn('acoes', fn ($p) => view('pages.painel.partials.pedido-acoes', ['pedido' => $p])->render())
            ->editColumn('total', fn ($p) => 'R$ ' . number_format((float) $p->total, 2, ',', '.'))
            ->editColumn('payment_method', fn ($p) => MercadoPagoStatus::metodo($p->payment_method))
            ->editColumn('status', fn ($p) => '<span class="status-badge ' . $p->status . '">' . ucfirst($p->status) . '</span>')
            ->editColumn('created_at', fn ($p) => $p->created_at?->format('d/m/Y H:i'))
            ->rawColumns(['status', 'acoes'])
            ->make(true);
    }

    /**
     * Ficha completa da compra — inclusive o que o Mercado Pago respondeu.
     *
     * É a tela pra onde se vai quando uma venda "sumiu": mostra o que o
     * gateway devolveu, traduzido, e a linha do tempo de tudo que aconteceu
     * com o pagamento.
     */
    public function show(Pedido $pedido): View
    {
        $this->autorizar($pedido);

        $pedido->load([
            'album:id,nome,slug,evento_id',
            'album.evento:id,nome',
            'user:id,nome,email',
            'cupom:id,codigo',
            'itens.video:id,nome,duracao_segundos',
            // Mais recente primeiro: quem abre a tela quer o último acontecimento.
            'pagamentoLogs' => fn ($q) => $q->orderByDesc('id')->limit(50),
        ]);

        $meta = $pedido->gateway_metadata ?? [];

        return view('pages.painel.pedido-detalhe', [
            'pedido' => $pedido,
            'metodoLabel' => MercadoPagoStatus::metodo($pedido->payment_method),
            'gatewayStatusLabel' => MercadoPagoStatus::status($pedido->gateway_status),
            'gatewayDetalhe' => MercadoPagoStatus::detalhe($meta['status_detail'] ?? null),
            // `message` aparece quando o MP recusa a própria requisição —
            // erro de integração, não de pagamento.
            'gatewayMensagem' => $meta['message'] ?? null,
            'gatewayCausas' => MercadoPagoStatus::causas($meta),
            'podeTrocarStatus' => auth()->user()->isAdmin(),
            'statusPermitidos' => $this->statusPermitidos($pedido),
        ]);
    }

    /**
     * Troca manual de status.
     *
     * Só admin: marcar um pedido como pago credita o saldo do vendedor, então
     * deixar o próprio dono do álbum fazer isso seria deixá-lo criar dinheiro
     * no sistema. Ele continua vendo a ficha completa do pedido dele.
     */
    public function atualizarStatus(Request $request, Pedido $pedido, PedidoPagamentoService $servico): JsonResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403, 'Só o administrador pode alterar o status de um pedido.');
        $this->autorizar($pedido);

        $dados = $request->validate([
            'status' => ['required', 'string'],
            // Obrigatório de propósito: mexer em pagamento na mão sem deixar
            // registro do porquê é o tipo de coisa que ninguém consegue
            // reconstruir seis meses depois.
            'motivo' => ['required', 'string', 'min:5', 'max:300'],
        ]);

        $permitidos = $this->statusPermitidos($pedido);
        if (! in_array($dados['status'], array_keys($permitidos), true)) {
            return response()->json([
                'message' => 'Essa mudança de status não é permitida a partir de "' . $pedido->status . '".',
            ], 422);
        }

        $motivo = auth()->user()->nome . ': ' . $dados['motivo'];

        match ($dados['status']) {
            Pedido::STATUS_PAGO => $servico->marcarComoPago($pedido, [], $motivo),
            Pedido::STATUS_CANCELADO => $servico->cancelar($pedido, $motivo),
            Pedido::STATUS_PENDENTE => $servico->reabrir($pedido, $motivo),
        };

        return response()->json([
            'message' => 'Status alterado para ' . $dados['status'] . '.',
        ]);
    }

    /**
     * Transições oferecidas a partir do status atual.
     *
     * 'pago' é ponto sem volta pela tela: o vendedor já foi creditado e pode
     * ter sacado o valor. Desfazer isso é estorno no gateway, com dinheiro
     * andando de verdade — não é coisa de um <select>.
     *
     * @return array<string,string> status => rótulo do botão
     */
    private function statusPermitidos(Pedido $pedido): array
    {
        return match ($pedido->status) {
            Pedido::STATUS_PENDENTE => [
                Pedido::STATUS_PAGO => 'Confirmar pagamento e liberar',
                Pedido::STATUS_CANCELADO => 'Cancelar pedido',
            ],
            Pedido::STATUS_CANCELADO => [
                Pedido::STATUS_PENDENTE => 'Reabrir para pagamento',
                Pedido::STATUS_PAGO => 'Confirmar pagamento e liberar',
            ],
            default => [],
        };
    }

    /** Cliente só enxerga pedido dos próprios álbuns. */
    private function autorizar(Pedido $pedido): void
    {
        abort_unless(
            auth()->user()->isAdmin() || $pedido->user_id === auth()->id(),
            404,
        );
    }
}
