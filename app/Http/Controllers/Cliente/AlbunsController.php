<?php

namespace App\Http\Controllers\Cliente;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessarVideoJob;
use App\Models\Album;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class AlbunsController extends Controller
{
    public function index(): View
    {
        $eventos = auth()->user()->eventos()->orderBy('nome')->get(['id', 'nome']);

        return view('pages.cliente.albuns', compact('eventos'));
    }

    public function data(Request $request): JsonResponse
    {
        $query = Album::query()
            ->withCount('videos')
            ->with('evento:id,nome')
            ->where('user_id', auth()->id())
            ->select(['id', 'evento_id', 'nome', 'subtitulo', 'preco', 'status', 'created_at']);

        return DataTables::eloquent($query)
            ->addColumn('evento', fn ($a) => $a->evento?->nome ?? '—')
            ->editColumn('preco', fn ($a) => 'R$ ' . number_format((float) $a->preco, 2, ',', '.'))
            ->editColumn('status', fn ($a) => '<span class="status-badge ' . $a->status . '">' . ucfirst($a->status) . '</span>')
            ->editColumn('created_at', fn ($a) => $a->created_at?->format('d/m/Y'))
            ->addColumn('acoes',
                fn ($a) => '<button class="btn btn-sm btn-outline-secondary me-1 js-upload" data-id="' . $a->id . '" title="Enviar vídeo"><i class="bi bi-upload"></i></button>'
                    . '<button class="btn btn-sm btn-outline-primary me-1 js-edit" data-id="' . $a->id . '"><i class="bi bi-pencil"></i></button>'
                    . '<button class="btn btn-sm btn-outline-danger js-delete" data-id="' . $a->id . '"><i class="bi bi-trash"></i></button>'
            )
            ->rawColumns(['status', 'acoes'])
            ->make(true);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'evento_id' => ['required', 'exists:eventos,id'],
            'nome' => ['required', 'string', 'max:255'],
            'subtitulo' => ['nullable', 'string', 'max:255'],
            'descricao' => ['nullable', 'string'],
            'preco' => ['required', 'numeric', 'min:0'],
            'status' => ['required', 'in:rascunho,publicado'],
        ]);

        abort_unless(auth()->user()->eventos()->whereKey($data['evento_id'])->exists(), 403);

        $album = auth()->user()->albuns()->create($data);

        return response()->json(['album' => $album, 'message' => 'Álbum criado.'], 201);
    }

    public function show(Album $album): JsonResponse
    {
        $this->authorizeDono($album);

        return response()->json($album);
    }

    public function update(Request $request, Album $album): JsonResponse
    {
        $this->authorizeDono($album);

        $data = $request->validate([
            'evento_id' => ['required', 'exists:eventos,id'],
            'nome' => ['required', 'string', 'max:255'],
            'subtitulo' => ['nullable', 'string', 'max:255'],
            'descricao' => ['nullable', 'string'],
            'preco' => ['required', 'numeric', 'min:0'],
            'status' => ['required', 'in:rascunho,publicado'],
        ]);

        abort_unless(auth()->user()->eventos()->whereKey($data['evento_id'])->exists(), 403);

        $album->update($data);

        return response()->json(['album' => $album, 'message' => 'Álbum atualizado.']);
    }

    public function destroy(Album $album): JsonResponse
    {
        $this->authorizeDono($album);
        $album->delete();

        return response()->json(['message' => 'Álbum removido.']);
    }

    public function uploadVideo(Request $request, Album $album): JsonResponse
    {
        $this->authorizeDono($album);

        $request->validate([
            'arquivo' => ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/x-matroska,video/webm', 'max:512000'],
        ]);

        $path = $request->file('arquivo')->store('videos/originais', 'local');

        $video = Video::create([
            'user_id' => auth()->id(),
            'album_id' => $album->id,
            'nome' => $request->file('arquivo')->getClientOriginalName(),
            'arquivo_original_path' => $path,
            'status' => Video::STATUS_PENDENTE,
            'tamanho_bytes' => $request->file('arquivo')->getSize(),
        ]);

        ProcessarVideoJob::dispatch($video->id);

        return response()->json(['video' => $video, 'message' => 'Vídeo enviado para processamento.'], 201);
    }

    private function authorizeDono(Album $album): void
    {
        abort_unless($album->user_id === auth()->id(), 403);
    }
}
