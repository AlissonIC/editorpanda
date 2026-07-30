<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessarVideoJob;
use App\Models\Album;
use App\Models\Configuracao;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class AlbunsController extends Controller
{
    public function index(): View
    {
        $eventos = auth()->user()->isAdmin()
            ? collect()
            : auth()->user()->eventos()->orderBy('nome')->get(['id', 'nome', 'preco_por_video']);

        return view('pages.painel.albuns', compact('eventos'));
    }

    public function data(Request $request): JsonResponse
    {
        $query = Album::query()
            ->select(['id', 'user_id', 'evento_id', 'slug', 'nome', 'subtitulo', 'preco', 'status', 'created_at'])
            ->withCount('videos')
            ->with(['user:id,nome', 'evento:id,nome']);

        if (! auth()->user()->isAdmin()) {
            $query->where('user_id', auth()->id());
        }

        $filters = $request->input('filters', []);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['evento_id'])) {
            $query->where('evento_id', $filters['evento_id']);
        }

        $isAdmin = auth()->user()->isAdmin();

        return DataTables::eloquent($query)
            ->editColumn('nome', function ($a) use ($isAdmin) {
                if ($isAdmin) return e($a->nome);
                return '<a href="' . route('painel.albuns.edit', $a->id) . '" class="fw-semibold text-decoration-none link-row">' . e($a->nome) . '</a>';
            })
            ->addColumn('cliente', fn ($a) => $a->user?->nome ?? '—')
            ->addColumn('evento', function ($a) use ($isAdmin) {
                if (! $a->evento) return '—';
                if ($isAdmin) return e($a->evento->nome);
                return '<a href="' . route('painel.eventos.edit', $a->evento->id) . '" class="text-decoration-none link-row-secondary">' . e($a->evento->nome) . '</a>';
            })
            ->editColumn('preco', fn ($a) => 'R$ ' . number_format((float) $a->preco, 2, ',', '.'))
            ->editColumn('status', fn ($a) => '<span class="status-badge ' . $a->status . '">' . ucfirst($a->status) . '</span>')
            ->editColumn('created_at', fn ($a) => $a->created_at?->format('d/m/Y'))
            ->addColumn('acoes', function ($a) use ($isAdmin) {
                if ($isAdmin) {
                    return '<button class="btn btn-sm btn-outline-danger js-delete" data-id="' . $a->id . '"><i class="bi bi-trash"></i></button>';
                }
                $shareUrl = route('publico.album.show', $a->slug);
                return '<a href="' . route('painel.albuns.enviar', $a) . '" class="btn btn-sm btn-outline-secondary me-1" title="Enviar vídeos"><i class="bi bi-upload"></i></a>'
                    . '<button class="btn btn-sm btn-outline-secondary me-1 js-share" data-url="' . e($shareUrl) . '" data-titulo="' . e($a->nome) . '" title="Compartilhar"><i class="bi bi-share"></i></button>'
                    . '<a href="' . route('painel.albuns.edit', $a->id) . '" class="btn btn-sm btn-outline-primary me-1" title="Editar"><i class="bi bi-pencil"></i></a>'
                    . '<button class="btn btn-sm btn-outline-danger js-delete" data-id="' . $a->id . '"><i class="bi bi-trash"></i></button>';
            })
            ->rawColumns(['nome', 'evento', 'status', 'acoes'])
            ->make(true);
    }

    public function store(Request $request): JsonResponse
    {
        abort_if(auth()->user()->isAdmin(), 403, 'Admin não cria álbuns.');

        $data = $request->validate([
            'evento_id' => ['required', 'exists:eventos,id'],
            'nome' => ['required', 'string', 'max:255'],
            'subtitulo' => ['nullable', 'string', 'max:255'],
            'descricao' => ['nullable', 'string'],
            'preco' => ['required', 'numeric', 'min:0'],
            'preco_por_video' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'status' => ['required', 'in:rascunho,publicado'],
            'tipo' => ['required', 'in:' . implode(',', Album::TIPOS)],
            'edicao_manual' => ['nullable', 'boolean'],
            'tempo_edicao_dias' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        // Preço vazio → null (herda do evento)
        if (! isset($data['preco_por_video']) || $data['preco_por_video'] === '' || $data['preco_por_video'] === null) {
            $data['preco_por_video'] = null;
        }

        // Edição manual só faz sentido em álbum de vídeo. Se veio setada num
        // álbum de imagem (front bugado, request forjado), força false.
        $data['edicao_manual'] = ($data['tipo'] === Album::TIPO_VIDEO)
            ? (bool) ($data['edicao_manual'] ?? false)
            : false;
        if (! $data['edicao_manual']) {
            $data['tempo_edicao_dias'] = null;
        }

        abort_unless(auth()->user()->eventos()->whereKey($data['evento_id'])->exists(), 403);

        $album = auth()->user()->albuns()->create($data);

        return response()->json(['album' => $album, 'message' => 'Álbum criado.'], 201);
    }

    public function show(Album $album): JsonResponse
    {
        $this->authorize($album);

        return response()->json($album);
    }

    /**
     * Página de edição completa — permite alterar dados básicos + trocar o evento.
     */
    public function edit(Album $album): View
    {
        abort_if(auth()->user()->isAdmin(), 403, 'Admin não edita álbuns.');
        $this->authorize($album);

        $eventos = auth()->user()->eventos()
            ->orderBy('nome')
            ->get(['id', 'nome', 'preco_por_video']);

        $album->load('evento:id,nome,preco_por_video');

        return view('pages.painel.albuns-editar', compact('album', 'eventos'));
    }

    public function update(Request $request, Album $album): JsonResponse
    {
        $this->authorize($album);
        abort_if(auth()->user()->isAdmin(), 403, 'Admin não edita álbuns diretamente.');

        $data = $request->validate([
            'evento_id' => ['required', 'exists:eventos,id'],
            'nome' => ['required', 'string', 'max:255'],
            'subtitulo' => ['nullable', 'string', 'max:255'],
            'descricao' => ['nullable', 'string'],
            'preco' => ['required', 'numeric', 'min:0'],
            'preco_por_video' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'status' => ['required', 'in:rascunho,publicado'],
            'tipo' => ['required', 'in:' . implode(',', Album::TIPOS)],
            'edicao_manual' => ['nullable', 'boolean'],
            'tempo_edicao_dias' => ['nullable', 'integer', 'min:1', 'max:365'],
            'descontos_quantidade' => ['nullable', 'array', 'max:10'],
            'descontos_quantidade.*.qtd' => ['required_with:descontos_quantidade.*', 'integer', 'min:1', 'max:1000'],
            'descontos_quantidade.*.percentual' => ['required_with:descontos_quantidade.*', 'numeric', 'min:0.01', 'max:100'],
        ]);

        // Edição manual só em álbum de vídeo — se front mandou pra imagem, ignora.
        $data['edicao_manual'] = ($data['tipo'] === Album::TIPO_VIDEO)
            ? (bool) ($data['edicao_manual'] ?? false)
            : false;
        if (! $data['edicao_manual']) {
            $data['tempo_edicao_dias'] = null;
        }

        // Trocar de 'video' pra 'imagem' (ou vice-versa) com itens já enviados
        // é uma incoerência que só admin resolve manualmente. Bloqueia trocar
        // se houver itens do tipo oposto ao novo — evita filtrar-e-mostrar
        // itens que não deveriam existir no álbum.
        if ($data['tipo'] !== $album->tipo && $album->videos()->exists()) {
            abort(response()->json([
                'message' => 'Não é possível mudar o tipo do álbum com vídeos/fotos já enviados. Remova os itens existentes primeiro.',
            ], 422));
        }

        // Preço vazio → null (herda do evento)
        if (! isset($data['preco_por_video']) || $data['preco_por_video'] === '' || $data['preco_por_video'] === null) {
            $data['preco_por_video'] = null;
        }

        // Sanitiza escada de desconto: remove degraus vazios, ordena por qtd
        if (! empty($data['descontos_quantidade'])) {
            $data['descontos_quantidade'] = collect($data['descontos_quantidade'])
                ->map(fn ($d) => ['qtd' => (int) $d['qtd'], 'percentual' => (float) $d['percentual']])
                ->sortBy('qtd')
                ->values()
                ->all();
        }

        abort_unless(auth()->user()->eventos()->whereKey($data['evento_id'])->exists(), 403);

        $album->update($data);

        return response()->json(['album' => $album, 'message' => 'Álbum atualizado.']);
    }

    public function destroy(Album $album): JsonResponse
    {
        $this->authorize($album);

        // Transaction: cascade delete → vídeos → decrement counter, tudo atômico.
        \DB::transaction(fn () => $album->delete());

        return response()->json(['message' => 'Álbum removido.']);
    }

    public function uploadPage(Album $album): View
    {
        abort_if(auth()->user()->isAdmin(), 403, 'Admin não envia vídeos.');
        $this->authorize($album);

        $album->load('evento:id,nome');
        $temPlanoAtivo = auth()->user()->temPlanoAtivo();

        return view('pages.painel.albuns-upload', compact('album', 'temPlanoAtivo'));
    }

    public function uploadVideo(Request $request, Album $album): JsonResponse
    {
        abort_if(auth()->user()->isAdmin(), 403, 'Admin não envia vídeos.');
        $this->authorize($album);

        $request->validate([
            // 1 GB (1048576 KB) — aceita vídeos e imagens (imagem vira MP4 estático no processamento).
            // Na prática esse endpoint legado quase nunca é usado (frontend usa multipart chunk).
            'arquivo' => [
                'required', 'file',
                'mimetypes:video/mp4,video/quicktime,video/x-matroska,video/webm,image/jpeg,image/png,image/webp,image/heic,image/heif',
                'max:1048576',
            ],
        ]);

        $tamanho = (int) $request->file('arquivo')->getSize();

        // Cota: verifica + reserva com lock atômico (previne race entre uploads concorrentes)
        $video = \DB::transaction(function () use ($request, $album, $tamanho) {
            $userId = auth()->id();
            $user = \App\Models\User::whereKey($userId)->lockForUpdate()->first();

            // Plano ativo é OBRIGATÓRIO para enviar vídeos
            if (! $user->temPlanoAtivo()) {
                abort(response()->json([
                    'message' => 'Você não tem plano ativo. Assine um plano para enviar vídeos.',
                    'sem_plano' => true,
                    'assinatura_url' => route('painel.assinatura.index'),
                ], 422));
            }

            $limite = $user->armazenamentoLimiteBytes();
            if ($limite !== null && ($user->armazenamento_bytes + $tamanho) > $limite) {
                $limiteGb = (int) ($user->plano?->armazenamento_gb ?? 0);
                $usadoGb = number_format($user->armazenamento_bytes / 1024 / 1024 / 1024, 2, ',', '.');
                abort(response()->json([
                    'message' => "Cota excedida: você está usando {$usadoGb} GB de {$limiteGb} GB. Remova conteúdo para liberar espaço.",
                ], 422));
            }

            \DB::table('users')->where('id', $userId)->update([
                'armazenamento_bytes' => \DB::raw('armazenamento_bytes + ' . $tamanho),
            ]);

            $disco = Configuracao::storageDisk();
            $path = $request->file('arquivo')->store('videos/originais', $disco);

            $originalName = $request->file('arquivo')->getClientOriginalName();

            $video = Video::create([
                'user_id' => $userId,
                'album_id' => $album->id,
                'nome' => $originalName, // placeholder — reescrito abaixo
                'arquivo_original_path' => $path,
                'disk' => $disco,
                'status' => Video::STATUS_PENDENTE,
                'tamanho_bytes' => $tamanho,
            ]);

            $video->update(['nome' => Video::gerarNomeArquivo($video->id, $originalName)]);

            return $video;
        });

        // Álbum de edição manual: sistema não processa. Marca concluído
        // usando o próprio arquivo enviado como preview público.
        if ($album->ehEdicaoManual()) {
            $video->update([
                'status' => Video::STATUS_CONCLUIDO,
                'arquivo_processado_path' => $video->arquivo_original_path,
                'arquivo_preview_path' => $video->arquivo_original_path,
                'processado_em' => now(),
            ]);
            return response()->json(['video' => $video, 'message' => 'Vídeo enviado (edição manual — sem processamento).'], 201);
        }

        ProcessarVideoJob::dispatch($video->id);

        return response()->json(['video' => $video, 'message' => 'Vídeo enviado para processamento.'], 201);
    }

    private function authorize(Album $album): void
    {
        if (auth()->user()->isAdmin()) {
            return;
        }
        abort_unless($album->user_id === auth()->id(), 403);
    }
}
