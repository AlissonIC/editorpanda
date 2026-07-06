<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Models\Evento;
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
}
