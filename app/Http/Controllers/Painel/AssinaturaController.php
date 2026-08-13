<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Assinatura;
use App\Models\Plano;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Tela da assinatura. Assinar/renovar/trocar passam pelo checkout cobrado —
 * ver AssinaturaCheckoutController. Aqui ficam a listagem e o cancelamento.
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

    public function cancelar(Request $request): JsonResponse
    {
        abort_if(auth()->user()->isAdmin(), 403);
        $user = auth()->user();

        $ativa = $user->assinaturas()->where('status', Assinatura::STATUS_ATIVA)->first();
        abort_if(! $ativa, 422, 'Você não tem uma assinatura ativa.');

        DB::transaction(function () use ($user, $ativa) {
            // Cancelada mas mantém o acesso até a data de expiração
            $ativa->update([
                'status' => Assinatura::STATUS_CANCELADA,
                'cancelado_em' => now(),
            ]);
            // Se expirou naturalmente antes de cancelar, limpa plano_id no user
            if ($ativa->expira_em->isPast()) {
                $user->update(['plano_id' => null, 'plano_expira_em' => null]);
            }
        });

        return response()->json([
            'message' => 'Assinatura cancelada. Você mantém acesso até ' . $ativa->expira_em->format('d/m/Y') . '.',
        ]);
    }
}
