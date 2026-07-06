<?php

namespace App\Http\Controllers\Painel;

use App\Http\Controllers\Controller;
use App\Models\Configuracao;
use App\Models\Evento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class EventosController extends Controller
{
    public function index(): View
    {
        return view('pages.painel.eventos');
    }

    public function data(Request $request): JsonResponse
    {
        $query = Evento::query()
            ->select(['id', 'user_id', 'slug', 'nome', 'localizacao_cidade', 'localizacao_estado', 'data', 'status'])
            ->withCount('albuns')
            ->with('user:id,nome');

        if (! auth()->user()->isAdmin()) {
            $query->where('user_id', auth()->id());
        }

        $filters = $request->input('filters', []);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return DataTables::eloquent($query)
            ->addColumn('cliente', fn ($e) => $e->user?->nome ?? '—')
            ->addColumn('localizacao', fn ($e) => trim(($e->localizacao_cidade ?: '') . ($e->localizacao_estado ? ' / ' . $e->localizacao_estado : '')) ?: '—')
            ->editColumn('data', fn ($e) => $e->data?->format('d/m/Y') ?? '—')
            ->editColumn('status', fn ($e) => '<span class="status-badge ' . $e->status . '">' . ucfirst($e->status) . '</span>')
            ->addColumn('acoes', function ($e) {
                if (auth()->user()->isAdmin()) {
                    return '<button class="btn btn-sm btn-outline-danger js-delete" data-id="' . $e->id . '"><i class="bi bi-trash"></i></button>';
                }
                $shareUrl = route('publico.evento.show', $e->slug);
                return '<button class="btn btn-sm btn-outline-secondary me-1 js-share" data-url="' . e($shareUrl) . '" data-titulo="' . e($e->nome) . '" title="Compartilhar"><i class="bi bi-share"></i></button>'
                    . '<button class="btn btn-sm btn-outline-primary me-1 js-edit" data-id="' . $e->id . '"><i class="bi bi-pencil"></i></button>'
                    . '<button class="btn btn-sm btn-outline-danger js-delete" data-id="' . $e->id . '"><i class="bi bi-trash"></i></button>';
            })
            ->rawColumns(['status', 'acoes'])
            ->make(true);
    }

    public function store(Request $request): JsonResponse
    {
        abort_if(auth()->user()->isAdmin(), 403, 'Admin não cria eventos.');

        $data = $this->validarDados($request);
        $evento = auth()->user()->eventos()->create($data);

        return response()->json(['evento' => $evento, 'message' => 'Evento criado.'], 201);
    }

    public function show(Evento $evento): JsonResponse
    {
        $this->authorize($evento);

        $payload = $evento->toArray();
        $payload['logo_url'] = $evento->logo_url;

        return response()->json($payload);
    }

    public function update(Request $request, Evento $evento): JsonResponse
    {
        $this->authorize($evento);
        abort_if(auth()->user()->isAdmin(), 403, 'Admin não edita eventos diretamente.');

        $data = $this->validarDados($request);
        $evento->update($data);

        return response()->json(['evento' => $evento, 'message' => 'Evento atualizado.']);
    }

    /**
     * Upload de logo do evento (PNG/SVG/JPG). Salva no disco vigente.
     */
    public function uploadLogo(Request $request, Evento $evento): JsonResponse
    {
        $this->authorize($evento);
        abort_if(auth()->user()->isAdmin(), 403);

        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048'], // 2 MB
        ]);

        $disco = Configuracao::storageDisk();

        // Remove antiga com verificação redundante
        if ($evento->logo_path) {
            \App\Support\StorageCleanup::deleteAndVerify(
                $evento->logo_disk ?: 'local',
                $evento->logo_path,
                'evento_logo_replace',
            );
        }

        $ext = strtolower($request->file('logo')->extension() ?: 'png');
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'png';
        $path = sprintf('logos-eventos/%d/evento-%d-%s.%s',
            auth()->id(), $evento->id, Str::random(8), $ext);

        $request->file('logo')->storeAs(dirname($path), basename($path), $disco);

        $evento->update(['logo_path' => $path, 'logo_disk' => $disco]);

        return response()->json([
            'logo_url' => $evento->fresh()->logo_url,
            'message' => 'Logo enviado.',
        ]);
    }

    public function deleteLogo(Evento $evento): JsonResponse
    {
        $this->authorize($evento);
        abort_if(auth()->user()->isAdmin(), 403);

        if ($evento->logo_path) {
            \App\Support\StorageCleanup::deleteAndVerify(
                $evento->logo_disk ?: 'local',
                $evento->logo_path,
                'evento_logo_delete',
            );
            $evento->update(['logo_path' => null, 'logo_disk' => null]);
        }

        return response()->json(['message' => 'Logo removido.']);
    }

    /**
     * Serve a logo do evento sem expor o path direto do storage privado.
     *   - local: stream via Storage::response()
     *   - s3: redirect pra presigned URL 15 min
     * 404 (não 403) quando não é dono, pra não vazar existência.
     */
    public function serveLogo(Evento $evento)
    {
        abort_unless(auth()->user()->isAdmin() || $evento->user_id === auth()->id(), 404);
        abort_unless($evento->logo_path, 404);

        $disco = $evento->logo_disk ?: 'local';
        if ($disco === 's3') {
            try {
                $url = Storage::disk('s3')->temporaryUrl($evento->logo_path, now()->addMinutes(15));
                return redirect()->away($url);
            } catch (\Throwable) {
                abort(500, 'Falha ao assinar URL do S3.');
            }
        }

        return Storage::disk('local')->response($evento->logo_path);
    }

    private function validarDados(Request $request): array
    {
        return $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'localizacao_cidade' => ['nullable', 'string', 'max:120'],
            'localizacao_estado' => ['nullable', 'string', 'max:100'],
            'data' => ['nullable', 'date'],
            'status' => ['required', 'in:ativo,inativo'],
            'preco_por_video' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'logo_posicao' => ['nullable', 'in:' . implode(',', Evento::POSICOES_LOGO)],
            'logo_escala' => ['nullable', 'numeric', 'min:0.05', 'max:0.5'],
            'gradiente_habilitado' => ['nullable', 'boolean'],
            'rosto_centralizar' => ['nullable', 'boolean'],
        ]);
    }

    public function destroy(Evento $evento): JsonResponse
    {
        $this->authorize($evento);

        // Transaction: garante consistência do contador de armazenamento em cascade
        // (evento → álbuns → vídeos → decrement counter). Se algum step falhar, tudo volta.
        \DB::transaction(fn () => $evento->delete());

        return response()->json(['message' => 'Evento removido.']);
    }

    private function authorize(Evento $evento): void
    {
        if (auth()->user()->isAdmin()) {
            return;
        }
        abort_unless($evento->user_id === auth()->id(), 403);
    }
}
