<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Cupom;
use App\Models\CupomEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * CRUD de cupons do produtor logado.
 *
 * IMPORTANTE: todos os selects filtram por auth()->id() — um produtor NUNCA
 * consegue enxergar/editar cupons de outro. Isso alia-se à validação em
 * Cupom::podeSerUsadoEm() (cupom.user_id === album.user_id no checkout).
 */
class CuponsController extends Controller
{
    public function index(): View
    {
        abort_if(auth()->user()->isAdmin(), 403);
        return view('pages.painel.cupons');
    }

    public function data(Request $request): JsonResponse
    {
        abort_if(auth()->user()->isAdmin(), 403);

        $cupons = Cupom::query()
            ->where('user_id', auth()->id())
            ->with(['albumRestrito:id,nome', 'eventoRestrito:id,nome'])
            ->withCount('emails')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Cupom $c) => [
                'id' => $c->id,
                'codigo' => $c->codigo,
                'tipo' => $c->tipo,
                'valor' => (float) $c->valor,
                'valor_formatado' => $c->tipo === Cupom::TIPO_PERCENTUAL
                    ? number_format((float) $c->valor, 2, ',', '.') . '%'
                    : 'R$ ' . number_format((float) $c->valor, 2, ',', '.'),
                'restricao_album' => $c->albumRestrito?->nome,
                'restricao_evento' => $c->eventoRestrito?->nome,
                'emails_count' => $c->emails_count,
                'limite_usos' => $c->limite_usos,
                'usos_atuais' => $c->usos_atuais,
                'expira_em' => $c->expira_em?->format('d/m/Y H:i'),
                'ativo' => (bool) $c->ativo,
            ]);

        return response()->json(['data' => $cupons]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_if(auth()->user()->isAdmin(), 403);
        return $this->salvar($request, null);
    }

    public function show(Cupom $cupom): JsonResponse
    {
        abort_unless($cupom->user_id === auth()->id(), 404);
        $cupom->load('emails');
        return response()->json([
            'cupom' => $cupom,
            'emails' => $cupom->emails->pluck('email'),
        ]);
    }

    public function update(Request $request, Cupom $cupom): JsonResponse
    {
        abort_unless($cupom->user_id === auth()->id(), 404);
        return $this->salvar($request, $cupom);
    }

    public function destroy(Cupom $cupom): JsonResponse
    {
        abort_unless($cupom->user_id === auth()->id(), 404);
        $cupom->delete();
        return response()->json(['message' => 'Cupom removido.']);
    }

    private function salvar(Request $request, ?Cupom $cupom): JsonResponse
    {
        // `after:now` só quando a data foi definida/alterada — o modal de edição
        // reenvia a data antiga, e um cupom já expirado precisa continuar editável
        // (ex.: só desativar ou corrigir o valor).
        $expiraEnviada = $request->input('expira_em');
        $expiraMudou = ($expiraEnviada ? strtotime($expiraEnviada) : null) !== $cupom?->expira_em?->getTimestamp();

        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:60', 'regex:/^[A-Z0-9_\-]+$/i'],
            'tipo' => ['required', 'in:percentual,fixo'],
            // Percentual é % (máx. 100); fixo é R$.
            'valor' => [
                'required', 'numeric', 'min:0.01',
                $request->input('tipo') === Cupom::TIPO_PERCENTUAL ? 'max:100' : 'max:9999.99',
            ],
            'restricao_album_id' => ['nullable', 'exists:albuns,id'],
            'restricao_evento_id' => ['nullable', 'exists:eventos,id'],
            'limite_usos' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'expira_em' => $expiraMudou ? ['nullable', 'date', 'after:now'] : ['nullable', 'date'],
            'ativo' => ['nullable', 'boolean'],
            'emails' => ['nullable', 'array', 'max:200'],
            'emails.*' => ['email', 'max:180'],
        ]);

        // ⚡ SEGURANÇA: restrições de álbum/evento têm que pertencer AO PRÓPRIO produtor.
        // Sem isso, um produtor podia criar cupom "válido" no álbum de outro
        // (ainda seria bloqueado no checkout, mas nem devia entrar no DB).
        if (! empty($data['restricao_album_id'])) {
            abort_unless(
                \App\Models\Album::whereKey($data['restricao_album_id'])
                    ->where('user_id', auth()->id())->exists(),
                403, 'Álbum de restrição não pertence a você.',
            );
        }
        if (! empty($data['restricao_evento_id'])) {
            abort_unless(
                \App\Models\Evento::whereKey($data['restricao_evento_id'])
                    ->where('user_id', auth()->id())->exists(),
                403, 'Evento de restrição não pertence a você.',
            );
        }

        // Restrições combinadas precisam ser coerentes: podeSerUsadoEm() exige
        // as duas ao mesmo tempo, então álbum de outro evento criaria um cupom
        // que nunca casa em checkout algum.
        if (! empty($data['restricao_album_id']) && ! empty($data['restricao_evento_id'])) {
            $pertence = \App\Models\Album::whereKey($data['restricao_album_id'])
                ->where('evento_id', $data['restricao_evento_id'])->exists();
            if (! $pertence) {
                return response()->json([
                    'message' => 'O álbum restrito não pertence ao evento restrito.',
                    'errors' => ['restricao_album_id' => ['Este álbum não pertence ao evento selecionado — o cupom nunca poderia ser usado.']],
                ], 422);
            }
        }

        $data['codigo'] = strtoupper($data['codigo']);
        $data['ativo'] = (bool) ($data['ativo'] ?? true);
        $emails = $data['emails'] ?? [];
        unset($data['emails']);

        // Unicidade codigo por user_id: validar manualmente com escopo
        $existente = Cupom::where('user_id', auth()->id())
            ->where('codigo', $data['codigo'])
            ->when($cupom, fn ($q) => $q->where('id', '!=', $cupom->id))
            ->exists();
        if ($existente) {
            return response()->json([
                'message' => 'Você já tem outro cupom com esse código.',
                'errors' => ['codigo' => ['Código já usado.']],
            ], 422);
        }

        $cupom = DB::transaction(function () use ($cupom, $data, $emails) {
            $data['user_id'] = auth()->id();
            if ($cupom) {
                $cupom->update($data);
            } else {
                $cupom = Cupom::create($data);
            }
            // Reset da whitelist de emails a cada save (mais previsível que diff)
            $cupom->emails()->delete();
            foreach ($emails as $e) {
                CupomEmail::create(['cupom_id' => $cupom->id, 'email' => $e]);
            }
            return $cupom;
        });

        return response()->json([
            'cupom' => $cupom->fresh()->load('emails'),
            'message' => 'Cupom salvo.',
        ], 201);
    }
}
