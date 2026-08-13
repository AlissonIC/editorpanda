<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Saque;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

/**
 * Fluxo de saque do vendedor (cliente).
 *
 * Design: valor é REBAIXADO do saldo_disponivel na solicitação (reserva).
 * Admin aprova → só marca pago. Admin recusa → devolve valor ao saldo.
 * Assim, saldo_disponivel sempre reflete o que está DE FATO livre.
 */
class SaquesController extends Controller
{
    private const VALOR_MINIMO = 20.00;

    public function index(): View
    {
        $user = auth()->user();

        return view('pages.painel.saques', [
            'saldo' => (float) $user->saldo_disponivel,
            'minimo' => self::VALOR_MINIMO,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $query = Saque::query()
            ->where('user_id', auth()->id())
            ->select(['id', 'valor', 'status', 'observacao', 'solicitado_em', 'pago_em']);

        return DataTables::eloquent($query)
            ->editColumn('valor', fn ($s) => 'R$ ' . number_format((float) $s->valor, 2, ',', '.'))
            ->editColumn('status', fn ($s) => '<span class="status-badge ' . $s->status . '">' . ucfirst($s->status) . '</span>')
            ->editColumn('solicitado_em', fn ($s) => $s->solicitado_em?->format('d/m/Y H:i'))
            ->editColumn('pago_em', fn ($s) => $s->pago_em?->format('d/m/Y H:i') ?? '—')
            ->rawColumns(['status'])
            ->make(true);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // `decimal:0,2` porque a coluna é decimal(12,2): sem isso, um valor
            // como 20,005 seria debitado com um arredondamento e gravado com
            // outro — o saque e o débito deixariam de bater por centavos.
            'valor' => ['required', 'numeric', 'decimal:0,2', 'min:' . self::VALOR_MINIMO, 'max:999999.99'],
            'dados_bancarios' => ['required', 'array'],
            'dados_bancarios.tipo' => ['required', 'in:pix,ted'],
            'dados_bancarios.chave' => ['required_if:dados_bancarios.tipo,pix', 'nullable', 'string', 'max:150'],
            'dados_bancarios.banco' => ['required_if:dados_bancarios.tipo,ted', 'nullable', 'string', 'max:100'],
            'dados_bancarios.agencia' => ['required_if:dados_bancarios.tipo,ted', 'nullable', 'string', 'max:20'],
            'dados_bancarios.conta' => ['required_if:dados_bancarios.tipo,ted', 'nullable', 'string', 'max:30'],
            'dados_bancarios.titular' => ['required', 'string', 'max:150'],
            'observacao' => ['nullable', 'string', 'max:500'],
        ]);

        $userId = auth()->id();
        $valorCents = (int) round((float) $data['valor'] * 100);

        // A conferência de saldo é AQUI, não no formulário. O campo do painel
        // avisa cedo, mas usa o saldo de quando a página carregou — que pode
        // estar velho (outra aba, outro saque, venda estornada) e é trivial de
        // burlar chamando a API direto.
        //
        // Lock no user para evitar race: dois POSTs simultâneos poderiam
        // ambos ver saldo >= valor e criar 2 saques, estourando o saldo.
        $saque = DB::transaction(function () use ($userId, $data, $valorCents) {
            $user = User::whereKey($userId)->lockForUpdate()->first();
            $saldoCents = (int) round((float) $user->saldo_disponivel * 100);

            if ($valorCents > $saldoCents) {
                abort(response()->json([
                    'message' => 'Saldo insuficiente.',
                    'errors' => ['valor' => ['Saldo disponível é R$ ' . number_format($user->saldo_disponivel, 2, ',', '.')]],
                ], 422));
            }

            // Debita o saldo (reserva) — só será liberado se admin recusar.
            DB::table('users')->where('id', $userId)->update([
                'saldo_disponivel' => DB::raw("saldo_disponivel - ({$valorCents} / 100)"),
            ]);

            return Saque::create([
                'user_id' => $userId,
                // Mesmo número que saiu do saldo, não o texto que veio no POST.
                'valor' => $valorCents / 100,
                'status' => 'solicitado',
                'dados_bancarios' => $data['dados_bancarios'],
                'observacao' => $data['observacao'] ?? null,
                'solicitado_em' => now(),
            ]);
        });

        return response()->json(['message' => 'Saque solicitado.', 'saque' => $saque], 201);
    }
}
