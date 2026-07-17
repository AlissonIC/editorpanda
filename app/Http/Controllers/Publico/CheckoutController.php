<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Comprador;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\User;
use App\Notifications\CompraFinalizadaNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class CheckoutController extends Controller
{
    public function store(Request $request, Album $album): JsonResponse
    {
        abort_unless($album->status === 'publicado' && $album->evento?->status === 'ativo', 404);

        $data = $request->validate([
            'nome' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'video_ids' => ['required', 'array', 'min:1', 'max:200'],
            'video_ids.*' => ['integer'],
        ]);

        // Dedup dentro do request
        $data['video_ids'] = array_values(array_unique(array_filter($data['video_ids'])));

        // Só vídeos ENTREGÁVEIS (processados). Vender "processando" é arriscado —
        // se falhar, comprador pagou por nada e não há fluxo de refund.
        $videos = $album->videos()
            ->whereIn('id', $data['video_ids'])
            ->where('status', 'concluido')
            ->get(['id', 'album_id']);

        abort_if($videos->isEmpty(), 422, 'Nenhum vídeo válido para compra.');

        // Dedup contra histórico: se o email já pagou aquele vídeo antes,
        // remove da compra. Evita double-charge quando o comprador esquece
        // que já comprou ou reenvia o form.
        $email = strtolower(trim($data['email']));
        $compradorExistente = Comprador::where('email', $email)->first();
        if ($compradorExistente) {
            $jaPagos = DB::table('pedido_itens')
                ->join('pedidos', 'pedidos.id', '=', 'pedido_itens.pedido_id')
                ->where('pedidos.comprador_id', $compradorExistente->id)
                ->where('pedidos.status', 'pago')
                ->whereIn('pedido_itens.video_id', $videos->pluck('id'))
                ->pluck('pedido_itens.video_id')
                ->all();
            if ($jaPagos) {
                $videos = $videos->reject(fn ($v) => in_array($v->id, $jaPagos, true))->values();
                abort_if($videos->isEmpty(), 422,
                    'Todos os vídeos selecionados já foram comprados por este e-mail.');
            }
        }

        $pedido = DB::transaction(function () use ($album, $data, $videos, $email) {
            // ATENÇÃO: preço vem SEMPRE do banco. Nunca do request. Snapshot com lock
            // do álbum + evento para não pegar leitura stale se o vendedor
            // alterar preço no meio da compra.
            $albumLocked = Album::whereKey($album->id)->lockForUpdate()->with('evento')->first();
            if ($albumLocked->evento_id) {
                \App\Models\Evento::whereKey($albumLocked->evento_id)->lockForUpdate()->first();
                $albumLocked->load('evento');
            }
            $preco = $albumLocked->precoEfetivoPorVideo();
            $total = round($preco * $videos->count(), 2);
            $ehGratis = $albumLocked->ehGratuito();

            // Anti-tampering: valida de novo com base no álbum bloqueado — se admin marcou
            // como "não publicado" no meio da transaction, aborta.
            if ($albumLocked->status !== 'publicado' || $albumLocked->evento?->status !== 'ativo') {
                abort(response()->json(['message' => 'Álbum indisponível.'], 404));
            }

            // Comprador: firstOrCreate por email (unique constraint garante consistência mesmo em race)
            $comprador = Comprador::firstOrCreate(
                ['email' => $email],
                ['nome' => $data['nome'], 'whatsapp' => $data['whatsapp'] ?? null]
            );
            $updates = array_filter([
                'nome' => $data['nome'],
                'whatsapp' => $data['whatsapp'] ?? null,
            ]);
            if ($updates) $comprador->update($updates);

            // Grátis → 'pago' direto sem gateway (não há o que cobrar).
            // Pago  → 'pago' também (MVP; quando integrar gateway, muda pra 'pendente' e libera após webhook).
            // A diferenciação fica registrada em `total` (0.00 vs >0) e no ehGratis pra auditoria.
            $pedido = Pedido::create([
                'album_id' => $album->id,
                'user_id' => $album->user_id,
                'comprador_id' => $comprador->id,
                'comprador_nome' => $comprador->nome,
                'comprador_email' => $comprador->email,
                'comprador_whatsapp' => $comprador->whatsapp,
                'total' => $total,
                'status' => 'pago',
                'pago_em' => now(),
                'gateway_id' => $ehGratis ? 'gratis' : null, // marca origem gratuita p/ relatórios
            ]);

            foreach ($videos as $v) {
                PedidoItem::create([
                    'pedido_id' => $pedido->id,
                    'video_id' => $v->id,
                    'preco_unit' => $preco,
                ]);
            }

            // Credita saldo apenas se houve receita real (total > 0).
            // Grátis não credita nada. Taxa do plano do vendedor é descontada
            // aqui — a diferença fica pra plataforma (não gravada em tabela ainda,
            // mas o total do pedido registra a receita bruta pra relatórios).
            if ($total > 0) {
                $vendedor = User::whereKey($album->user_id)->lockForUpdate()->with('plano')->first();
                $taxa = (float) ($vendedor?->plano?->taxa_por_venda ?? 0);
                $credito = round($total * (1 - $taxa / 100), 2);
                $creditoCents = (int) round($credito * 100);
                if ($creditoCents > 0) {
                    DB::table('users')->where('id', $vendedor->id)->update([
                        'saldo_disponivel' => DB::raw("saldo_disponivel + ({$creditoCents} / 100)"),
                    ]);
                }
            }

            return $pedido;
        });

        // Magic link automático pós-compra
        [$tokenPlano] = \App\Models\AcessoToken::gerarPara(
            $pedido->comprador_email,
            $request->ip(),
            $request->userAgent(),
        );
        $urlAcesso = route('publico.acesso.validar', ['token' => $tokenPlano]);

        try {
            $pedido->comprador?->notify(new CompraFinalizadaNotification($pedido, $urlAcesso));
        } catch (\Throwable $e) {
            \Log::warning('Falha ao enviar email de compra', ['pedido' => $pedido->id, 'erro' => $e->getMessage()]);
        }

        // URL de confirmação temporariamente ASSINADA (30 min) — impede que copiar+compartilhar exponha o pedido
        $redirectUrl = URL::temporarySignedRoute(
            'publico.checkout.confirmacao',
            now()->addMinutes(30),
            ['pedido' => $pedido->id],
        );

        return response()->json([
            'pedido_id' => $pedido->id,
            'redirect' => $redirectUrl,
        ]);
    }

    public function confirmacao(Pedido $pedido, Request $request)
    {
        $pedido->load(['itens.video', 'album:id,nome,slug', 'comprador']);

        // Acesso: URL assinada (recém-comprada, válida por 30min) OU comprador logado
        $viaAssinatura = $request->hasValidSignature();
        $comprador = auth('comprador')->user();
        $viaLogin = $comprador && $comprador->id === $pedido->comprador_id;

        abort_unless($viaAssinatura || $viaLogin, 403,
            'Faça login pelo link enviado por e-mail para ver este pedido.');

        return view('pages.publico.checkout-confirmacao', [
            'pedido' => $pedido,
        ]);
    }
}
