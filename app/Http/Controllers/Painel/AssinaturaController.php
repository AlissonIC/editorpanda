<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Assinatura;
use App\Models\Plano;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AssinaturaController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();
        abort_if($user->isAdmin(), 403, 'Assinaturas são só para clientes.');

        $assinaturaAtual = $user->assinaturaAtiva();
        $historico = $user->assinaturas()
            ->with('plano:id,nome')
            ->limit(30)
            ->get();

        $planosDisponiveis = Plano::ativos()->ordenados()->get();

        return view('pages.painel.assinatura', compact(
            'assinaturaAtual', 'historico', 'planosDisponiveis'
        ));
    }

    /**
     * Cria uma nova assinatura para o plano informado. Se já houver ativa,
     * cancela a anterior (troca de plano) e inicia a nova a partir de agora.
     *
     * MVP: sem gateway real — marcamos como paga direto. Integração de gateway
     * entra depois (ex.: Mercado Pago) preenchendo `gateway_id`.
     */
    public function assinar(Request $request, Plano $plano): JsonResponse
    {
        abort_if(auth()->user()->isAdmin(), 403);
        abort_unless($plano->ativo, 422, 'Este plano não está disponível.');

        $user = auth()->user();

        $novaAssinatura = DB::transaction(function () use ($user, $plano) {
            // Cancela ativa anterior (troca de plano)
            $ativa = $user->assinaturas()->where('status', Assinatura::STATUS_ATIVA)->lockForUpdate()->first();
            if ($ativa) {
                $ativa->update([
                    'status' => Assinatura::STATUS_CANCELADA,
                    'cancelado_em' => now(),
                ]);
            }

            $iniciadoEm = now();
            $expiraEm = now()->addDays(30);

            $assinatura = Assinatura::create([
                'user_id' => $user->id,
                'plano_id' => $plano->id,
                'plano_nome' => $plano->nome,
                'preco_pago' => $plano->preco,
                'duracao_dias' => 30,
                'iniciado_em' => $iniciadoEm,
                'expira_em' => $expiraEm,
                'status' => Assinatura::STATUS_ATIVA,
            ]);

            $user->update([
                'plano_id' => $plano->id,
                'plano_expira_em' => $expiraEm,
            ]);

            return $assinatura;
        });

        return response()->json([
            'message' => 'Plano ' . $plano->nome . ' assinado com sucesso.',
            'assinatura' => $novaAssinatura->fresh(),
        ]);
    }

    /**
     * Renovação: estende a assinatura ativa atual por mais 30 dias.
     * Se estiver expirada/cancelada, precisa assinar de novo (via /assinar).
     */
    public function renovar(Request $request): JsonResponse
    {
        abort_if(auth()->user()->isAdmin(), 403);
        $user = auth()->user();

        $renovacao = DB::transaction(function () use ($user) {
            $ativa = $user->assinaturas()
                ->where('status', Assinatura::STATUS_ATIVA)
                ->lockForUpdate()
                ->first();

            abort_if(! $ativa, 422, 'Nenhuma assinatura ativa para renovar. Assine um plano.');

            // Se ainda não expirou, estende a partir da data atual de expiração.
            // Se já expirou (edge case), estende a partir de agora.
            $novaExpira = $ativa->expira_em->isPast()
                ? now()->addDays($ativa->duracao_dias ?: 30)
                : $ativa->expira_em->copy()->addDays($ativa->duracao_dias ?: 30);

            $plano = $ativa->plano;
            $preco = $plano?->preco ?? $ativa->preco_pago;

            // Cria uma NOVA linha (histórico limpo) e cancela a anterior
            $ativa->update([
                'status' => Assinatura::STATUS_CANCELADA,
                'cancelado_em' => now(),
            ]);
            $nova = Assinatura::create([
                'user_id' => $user->id,
                'plano_id' => $ativa->plano_id,
                'plano_nome' => $ativa->plano_nome,
                'preco_pago' => $preco,
                'duracao_dias' => $ativa->duracao_dias ?: 30,
                'iniciado_em' => now(),
                'expira_em' => $novaExpira,
                'status' => Assinatura::STATUS_ATIVA,
            ]);
            $user->update(['plano_expira_em' => $novaExpira]);

            return $nova;
        });

        return response()->json([
            'message' => 'Assinatura renovada até ' . $renovacao->expira_em->format('d/m/Y') . '.',
            'assinatura' => $renovacao->fresh(),
        ]);
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
