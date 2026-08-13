<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Assinatura;
use App\Models\Plano;
use Illuminate\View\View;

/**
 * Tela da assinatura — só leitura. Assinar, renovar e trocar passam pelo
 * checkout cobrado (ver AssinaturaCheckoutController).
 *
 * Não existe cancelamento pelo cliente, e é de propósito: o plano é comprado
 * por 30 dias fechados, sem cobrança recorrente. Não há o que interromper —
 * cancelar só encurtaria um período que ele já pagou. Quem não quer seguir,
 * não renova.
 *
 * O status `cancelada` continua existindo no banco, mas para uso interno:
 * troca de plano e cobrança recusada.
 */
class AssinaturaController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();
        abort_if($user->isAdmin(), 403, 'Assinaturas são só para clientes.');

        $assinaturaAtual = $user->assinaturaAtiva();
        // Pendente fica de fora: é cobrança aberta, não assinatura. Um PIX
        // abandonado no meio do checkout não vira linha no histórico (e não
        // tem iniciado_em/expira_em pra mostrar).
        $historico = $user->assinaturas()
            ->with('plano:id,nome')
            ->where('status', '!=', Assinatura::STATUS_PENDENTE)
            ->limit(30)
            ->get();

        $planosDisponiveis = Plano::ativos()->ordenados()->get();

        return view('pages.painel.assinatura', compact(
            'assinaturaAtual', 'historico', 'planosDisponiveis'
        ));
    }

}
