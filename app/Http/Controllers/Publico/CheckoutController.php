<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Comprador;
use App\Models\Cupom;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\User;
use App\Models\Video;
use App\Notifications\CompraFinalizadaNotification;
use App\Notifications\CompraGratuitaNotification;
use App\Services\PedidoMergeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class CheckoutController extends Controller
{
    /**
     * Pré-checagem chamada quando o comprador informa o e-mail no form.
     * Devolve quais dos vídeos selecionados JÁ foram comprados por esse email
     * antes — permite mostrar overlay elegante em vez de deixar o comprador
     * clicar em "Finalizar compra" pra descobrir tarde demais.
     */
    public function verificarEmail(Request $request, Album $album): JsonResponse
    {
        abort_unless($album->status === 'publicado' && $album->evento?->status === 'ativo', 404);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:180'],
            'video_ids' => ['required', 'array', 'min:1', 'max:200'],
            'video_ids.*' => ['integer'],
        ]);

        $email = strtolower(trim($data['email']));
        $videoIds = array_values(array_unique(array_filter($data['video_ids'])));

        $comprador = Comprador::where('email', $email)->first();
        if (! $comprador) {
            return response()->json(['ja_comprados' => []]);
        }

        $jaPagos = DB::table('pedido_itens')
            ->join('pedidos', 'pedidos.id', '=', 'pedido_itens.pedido_id')
            ->where('pedidos.comprador_id', $comprador->id)
            ->where('pedidos.status', 'pago')
            ->whereIn('pedido_itens.video_id', $videoIds)
            ->pluck('pedido_itens.video_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return response()->json(['ja_comprados' => $jaPagos]);
    }

    public function store(Request $request, Album $album): JsonResponse
    {
        abort_unless($album->status === 'publicado' && $album->evento?->status === 'ativo', 404);

        // Guard-rail: álbum pago EXIGE MP configurado. Sem isso o front recebe
        // public_key=null e o modal do Bricks abre em branco silenciosamente.
        // Falha rápido aqui com mensagem clara em vez de deixar o cliente perdido.
        if (! $album->ehGratuito()) {
            $accessToken = config('services.mercadopago.access_token');
            $publicKey = config('services.mercadopago.public_key');
            if (! $accessToken || ! $publicKey) {
                abort(response()->json([
                    'message' => 'Pagamentos temporariamente indisponíveis. Fale com o organizador do evento.',
                ], 503));
            }
        }

        $data = $request->validate([
            'nome' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'whatsapp' => ['required', 'string', 'min:10', 'max:20'],
            'video_ids' => ['required', 'array', 'min:1', 'max:200'],
            'video_ids.*' => ['integer'],
            'codigo_cupom' => ['nullable', 'string', 'max:60'],
            // Opt-in do "recebo tudo num arquivo só". Sem custo extra — é o
            // mesmo material, só concatenado.
            'mesclar' => ['sometimes', 'boolean'],
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
                ->map(fn ($id) => (int) $id)
                ->all();
            if ($jaPagos) {
                $videos = $videos->reject(fn ($v) => in_array($v->id, $jaPagos, true))->values();
                if ($videos->isEmpty()) {
                    // Devolve os IDs pro front renderizar o overlay elegante
                    // (com botões "Acessar conta" / "Remover do carrinho"),
                    // em vez de só uma mensagem-toast.
                    abort(response()->json([
                        'message' => 'Todos os vídeos selecionados já foram comprados por este e-mail.',
                        'ja_comprados' => $jaPagos,
                    ], 422));
                }
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
            $qtd = $videos->count();
            $subtotal = round($preco * $qtd, 2);

            // Desconto por quantidade (escada configurada no álbum ou herdada do evento)
            $descontoQtdPct = $albumLocked->percentualDescontoQuantidade($qtd);
            $descontoQtd = round($subtotal * ($descontoQtdPct / 100), 2);

            // Desconto por CUPOM (aplicado após qtd, sobre o valor já com desconto).
            // SEGURANÇA: resolvemos o cupom já filtrando por user_id do álbum —
            // impossível usar cupom de outro produtor mesmo forjando o código.
            $cupom = null;
            $descontoCupom = 0.0;
            if (! empty($data['codigo_cupom'])) {
                $cupom = Cupom::where('user_id', $albumLocked->user_id)
                    ->where('codigo', strtoupper(trim($data['codigo_cupom'])))
                    ->lockForUpdate()
                    ->first();
                if (! $cupom) {
                    abort(response()->json(['message' => 'Cupom inválido.'], 422));
                }
                $ok = $cupom->podeSerUsadoEm($albumLocked, $email);
                if (! $ok['ok']) {
                    abort(response()->json(['message' => $ok['motivo']], 422));
                }
                $descontoCupom = $cupom->calcularDesconto($subtotal - $descontoQtd);
            }

            $total = round($subtotal - $descontoQtd - $descontoCupom, 2);
            if ($total < 0) $total = 0.0;
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
            // Pago  → 'aguardando_pagamento' — o front chama /pedido/{id}/pagamento/{pix|cartao}
            //        pra criar o payment no MP, e polla status até aprovar/rejeitar.
            $statusInicial = $ehGratis ? Pedido::STATUS_PAGO : Pedido::STATUS_PENDENTE;
            $pedido = Pedido::create([
                'album_id' => $album->id,
                'user_id' => $album->user_id,
                'comprador_id' => $comprador->id,
                'comprador_nome' => $comprador->nome,
                'comprador_email' => $comprador->email,
                'comprador_whatsapp' => $comprador->whatsapp,
                'total' => $total,
                'cupom_id' => $cupom?->id,
                'desconto_cupom' => $descontoCupom > 0 ? $descontoCupom : null,
                'desconto_quantidade' => $descontoQtd > 0 ? $descontoQtd : null,
                'status' => $statusInicial,
                // Só faz sentido com 2+ itens sobrevivendo ao dedup — se o
                // comprador marcou e um dos vídeos caiu fora, a flag morre aqui.
                'mesclar_solicitado' => ! empty($data['mesclar']) && $videos->count() >= 2,
                'pago_em' => $ehGratis ? now() : null,
                'gateway_id' => $ehGratis ? 'gratis' : null, // marca origem gratuita p/ relatórios
            ]);

            // Uso do cupom: fluxo grátis nasce pago, então consome aqui (mesma
            // transaction + lockForUpdate acima). Fluxo pago só consome quando o
            // pagamento confirmar (PedidoPagamentoService::marcarComoPago) —
            // senão checkout abandonado esgotaria o limite sem gerar venda.
            if ($cupom && $ehGratis) {
                $cupom->increment('usos_atuais');
            }

            foreach ($videos as $v) {
                PedidoItem::create([
                    'pedido_id' => $pedido->id,
                    'video_id' => $v->id,
                    'preco_unit' => $preco,
                ]);
            }

            // Crédito ao vendedor: SÓ ao confirmar pagamento (não aqui, pois
            // o pedido acabou de nascer como 'aguardando_pagamento' pra fluxo
            // pago). Grátis não gera crédito. Ver PagamentoController::creditarVendedor.
            return $pedido;
        });

        // FLUXO DE EVENTO GRATUITO: entrega direta por e-mail — o comprador NÃO
        // vai pra área do comprador, recebe links assinados de download direto.
        // Mais fricção-free pra fluxo de brinde/gratuito.
        if ($album->ehGratuito()) {
            // Gratuito já nasce pago — enfileira a mescla na hora. O e-mail sai
            // com os links individuais (o concat é assíncrono); o arquivo único
            // aparece em "Minhas compras" quando o ffmpeg termina.
            $mergeGratis = app(PedidoMergeService::class)->criarSeSolicitado($pedido);

            $links = $videos->map(fn (Video $v) => [
                'nome' => $v->fresh()->nome_exibicao,
                'url' => URL::temporarySignedRoute(
                    'publico.video.baixar-livre',
                    now()->addDays(30),
                    ['video' => $v->id],
                ),
            ])->all();

            try {
                $pedido->comprador?->notify(new CompraGratuitaNotification($pedido, $links));
            } catch (\Throwable $e) {
                \Log::warning('Falha ao enviar email gratuito', ['pedido' => $pedido->id, 'erro' => $e->getMessage()]);
            }

            return response()->json([
                'pedido_id' => $pedido->id,
                'gratis' => true,
                'message' => $mergeGratis
                    ? 'Enviamos os arquivos por e-mail em instantes. O vídeo único aparece em "Minhas compras" assim que ficar pronto.'
                    : 'Enviamos os arquivos por e-mail em instantes.',
            ]);
        }

        // Fluxo pago: NÃO redireciona pra confirmação — o front vai renderizar
        // a UI de pagamento (Bricks cartão + PIX QR) inline. Só depois de
        // aprovado é que o redirect pra /pedido/{id} rola (via retorno).
        // Devolve pedido_id + total + public_key + expiração — front cuida do resto.
        return response()->json([
            'pedido_id' => $pedido->id,
            'total' => (float) $pedido->total,
            'public_key' => config('services.mercadopago.public_key'),
            'sandbox' => (bool) config('services.mercadopago.sandbox'),
        ]);
    }

    /**
     * Download livre de vídeo/foto de EVENTO GRATUITO via URL assinada.
     * Enviado por e-mail no fluxo do CompraGratuitaNotification.
     * Middleware `signed` valida integridade + expiração; aqui checamos que
     * o vídeo realmente pertence a um evento marcado como gratuito.
     */
    public function baixarGratis(Video $video)
    {
        $video->load('album.evento');
        abort_unless($video->album?->evento?->ehGratuito(), 404);
        abort_unless($video->status === 'concluido', 404);
        abort_unless($video->arquivo_processado_path, 404);

        $disk = $video->disk ?: 'local';
        $path = $video->arquivo_processado_path;
        $nome = $video->nomeArquivoDownload('processado');

        if ($disk === 's3') {
            try {
                $url = Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(15), [
                    'ResponseContentDisposition' => 'attachment; filename="' . $nome . '"',
                ]);
                return redirect()->away($url);
            } catch (\Throwable) {
                abort(500);
            }
        }

        return Storage::disk('local')->download($path, $nome);
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

        // URLs de download assinadas de curta duração — geradas SÓ quando o
        // acesso é legítimo. A view usa em vez de exigir @auth('comprador'),
        // que quebrava o fluxo recém-checkout (URL assinada mas usuário não
        // autenticado ainda). Válidas por 2h — tempo suficiente pra baixar
        // todos, e pequeno o bastante pra não virar link permanente.
        //
        // Álbum de edição manual: NÃO gera download — entrega é externa,
        // o comprador vê mensagem informando o prazo.
        $edicaoManual = $pedido->album?->ehEdicaoManual() ?? false;
        $downloadUrls = [];
        if ($pedido->status === 'pago' && ! $edicaoManual) {
            foreach ($pedido->itens as $item) {
                if ($item->video?->status !== 'concluido') continue;
                $downloadUrls[$item->video_id] = URL::temporarySignedRoute(
                    'publico.pedido.baixar-item',
                    now()->addHours(2),
                    ['pedido' => $pedido->id, 'video' => $item->video_id],
                );
            }
        }

        return view('pages.publico.checkout-confirmacao', [
            'pedido' => $pedido,
            'downloadUrls' => $downloadUrls,
            'edicaoManual' => $edicaoManual,
            // Mescla pedida no checkout: aqui só informamos o andamento. O
            // download exige sessão de comprador (o acesso a esta página pode
            // ser só pela URL assinada, sem login).
            'merge' => $pedido->mesclar_solicitado
                ? $pedido->merges()->whereNotNull('comprador_id')->orderByDesc('id')->first()
                : null,
        ]);
    }

    /**
     * Download de item comprado — via URL assinada pontual (não exige login).
     * Segurança: URL assinada (integridade), item PRECISA pertencer ao pedido,
     * pedido PRECISA estar pago. O signed middleware valida integridade+expiração.
     */
    public function baixarItemPedido(Pedido $pedido, Video $video)
    {
        abort_unless($pedido->status === 'pago', 404);
        abort_unless($video->status === 'concluido', 404);

        $pertence = $pedido->itens()->where('video_id', $video->id)->exists();
        abort_unless($pertence, 404);

        $disk = $video->disk ?: 'local';
        $path = $video->arquivo_processado_path;
        abort_unless($path, 404);
        $nome = $video->nomeArquivoDownload('processado');

        if ($disk === 's3') {
            try {
                $url = Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(15), [
                    'ResponseContentDisposition' => 'attachment; filename="' . $nome . '"',
                ]);
                return redirect()->away($url);
            } catch (\Throwable) {
                abort(500);
            }
        }
        return Storage::disk('local')->download($path, $nome);
    }
}
