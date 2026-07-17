<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class EventoPublicoController extends Controller
{
    public function show(Evento $evento): View
    {
        abort_unless($evento->status === 'ativo', 404);

        $evento->load(['user:id,nome']);
        $albuns = $evento->albuns()
            ->where('status', 'publicado')
            ->withCount('videos')
            ->orderBy('nome')
            ->get(['id', 'evento_id', 'slug', 'nome', 'subtitulo', 'capa_path', 'preco_por_video']);

        return view('pages.publico.evento', [
            'evento' => $evento,
            'albuns' => $albuns,
            'precoEvento' => $evento->precoEfetivoPorVideo(),
        ]);
    }

    /**
     * Serve a capa do evento.
     *   - Público: só se evento ativo
     *   - Dono/admin logado: sempre (permite preview no editor mesmo com evento inativo)
     */
    public function servirCapa(Evento $evento)
    {
        $liberado = $evento->status === 'ativo'
            || (auth()->check() && (auth()->user()->isAdmin() || $evento->user_id === auth()->id()));
        abort_unless($liberado, 404);
        abort_unless($evento->capa_path, 404);

        $disco = $evento->capa_disk ?: 'local';
        if ($disco === 's3') {
            try {
                $url = Storage::disk('s3')->temporaryUrl($evento->capa_path, now()->addMinutes(15));
                return redirect()->away($url);
            } catch (\Throwable) {
                abort(500);
            }
        }
        return Storage::disk('local')->response($evento->capa_path);
    }

    /**
     * Serve o logo do evento na página pública.
     * Só quando evento ativo. Sem essa rota, o <img> da página pública apontava
     * pra rota auth+aprovado e visitantes recebiam 302 pra login.
     */
    public function servirLogo(Evento $evento)
    {
        abort_unless($evento->status === 'ativo', 404);
        abort_unless($evento->logo_path, 404);

        $disco = $evento->logo_disk ?: 'local';
        if ($disco === 's3') {
            try {
                $url = Storage::disk('s3')->temporaryUrl($evento->logo_path, now()->addMinutes(15));
                return redirect()->away($url);
            } catch (\Throwable) {
                abort(500);
            }
        }
        return Storage::disk('local')->response($evento->logo_path);
    }
}
