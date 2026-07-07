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

        return DataTables::eloquent($query)
            ->addColumn('cliente', fn ($a) => $a->user?->nome ?? '—')
            ->addColumn('evento', fn ($a) => $a->evento?->nome ?? '—')
            ->editColumn('preco', fn ($a) => 'R$ ' . number_format((float) $a->preco, 2, ',', '.'))
            ->editColumn('status', fn ($a) => '<span class="status-badge ' . $a->status . '">' . ucfirst($a->status) . '</span>')
            ->editColumn('created_at', fn ($a) => $a->created_at?->format('d/m/Y'))
            ->addColumn('acoes', function ($a) {
                if (auth()->user()->isAdmin()) {
                    return '<button class="btn btn-sm btn-outline-danger js-delete" data-id="' . $a->id . '"><i class="bi bi-trash"></i></button>';
                }
                $shareUrl = route('publico.album.show', $a->slug);
                return '<a href="' . route('painel.albuns.enviar', $a) . '" class="btn btn-sm btn-outline-secondary me-1" title="Enviar vídeos"><i class="bi bi-upload"></i></a>'
                    . '<button class="btn btn-sm btn-outline-secondary me-1 js-share" data-url="' . e($shareUrl) . '" data-titulo="' . e($a->nome) . '" title="Compartilhar"><i class="bi bi-share"></i></button>'
                    . '<button class="btn btn-sm btn-outline-primary me-1 js-edit" data-id="' . $a->id . '"><i class="bi bi-pencil"></i></button>'
                    . '<button class="btn btn-sm btn-outline-danger js-delete" data-id="' . $a->id . '"><i class="bi bi-trash"></i></button>';
            })
            ->rawColumns(['status', 'acoes'])
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
        ]);

        // Preço vazio → null (herda do evento)
        if (! isset($data['preco_por_video']) || $data['preco_por_video'] === '' || $data['preco_por_video'] === null) {
            $data['preco_por_video'] = null;
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
        ]);

        // Preço vazio → null (herda do evento)
        if (! isset($data['preco_por_video']) || $data['preco_por_video'] === '' || $data['preco_por_video'] === null) {
            $data['preco_por_video'] = null;
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
        $disco = Configuracao::storageDisk();
        $temPlanoAtivo = auth()->user()->temPlanoAtivo();

        return view('pages.painel.albuns-upload', compact('album', 'disco', 'temPlanoAtivo'));
    }

    public function uploadVideo(Request $request, Album $album): JsonResponse
    {
        abort_if(auth()->user()->isAdmin(), 403, 'Admin não envia vídeos.');
        $this->authorize($album);

        $request->validate([
            'arquivo' => ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/x-matroska,video/webm', 'max:512000'],
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

            return Video::create([
                'user_id' => $userId,
                'album_id' => $album->id,
                'nome' => $request->file('arquivo')->getClientOriginalName(),
                'arquivo_original_path' => $path,
                'disk' => $disco,
                'status' => Video::STATUS_PENDENTE,
                'tamanho_bytes' => $tamanho,
            ]);
        });

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
