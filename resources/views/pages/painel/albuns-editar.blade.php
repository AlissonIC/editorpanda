@extends('theme::layouts.painel')

@section('titulo', 'Editar álbum — ' . $album->nome)

@section('conteudo')
<x-theme::page-header
    titulo="Editar álbum"
    subtitulo="{{ $album->nome }}"
>
    <a href="{{ route('painel.albuns.index') }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
    <a href="{{ route('painel.albuns.enviar', $album) }}" class="btn btn-outline-secondary">
        <i class="bi bi-upload me-1"></i> Enviar vídeos
    </a>
    <a href="{{ route('publico.album.show', $album->slug) }}" target="_blank" class="btn btn-outline-secondary">
        <i class="bi bi-box-arrow-up-right me-1"></i> Ver página pública
    </a>
</x-theme::page-header>

<form id="form-album-editar"
      data-url="{{ route('painel.albuns.update', $album) }}"
      data-eventos="{{ json_encode($eventos->map(fn($e) => ['id' => $e->id, 'preco_por_video' => (float) $e->preco_por_video])) }}"
      novalidate>
    @csrf

    <div class="row g-4">
        <div class="col-lg-8">
            {{-- Informações --}}
            <div class="panda-card mb-4">
                <h5 class="fw-bold mb-3">Informações</h5>

                <div class="row g-3">
                    <div class="col-md-5 mb-3">
                        <label class="form-label small">Evento</label>
                        <select name="evento_id" class="form-select" required id="alb-evento">
                            @foreach($eventos as $ev)
                                <option value="{{ $ev->id }}" @selected($ev->id === $album->evento_id)>{{ $ev->nome }}</option>
                            @endforeach
                        </select>
                        <div class="invalid-feedback" data-field="evento_id"></div>
                        <small class="text-muted">
                            Mover este álbum para outro evento.
                            <a href="{{ route('painel.eventos.edit', $album->evento_id) }}" class="ms-1">Editar evento atual</a>
                        </small>
                    </div>

                    <div class="col-md-7 mb-3">
                        <label class="form-label small">Nome</label>
                        <input type="text" name="nome" value="{{ $album->nome }}" class="form-control" required>
                        <div class="invalid-feedback" data-field="nome"></div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small">Subtítulo</label>
                    <input type="text" name="subtitulo" value="{{ $album->subtitulo }}" class="form-control">
                </div>

                <div class="mb-3">
                    <label class="form-label small">Descrição</label>
                    <textarea name="descricao" class="form-control" rows="4"
                              placeholder="Sobre este álbum…">{{ $album->descricao }}</textarea>
                </div>
            </div>

            {{-- Preços & status --}}
            <div class="panda-card mb-4">
                <h5 class="fw-bold mb-3">Preço e status</h5>

                <div class="row g-3">
                    <div class="col-md-4 mb-3">
                        <label class="form-label small">Preço fixo do álbum</label>
                        <input type="text" name="preco" data-mask="money"
                               value="{{ number_format((float) $album->preco, 2, '.', '') }}"
                               class="form-control" required>
                        <small class="text-muted">Pacote com todos os vídeos; opcional.</small>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label class="form-label small">Preço por vídeo</label>
                        <input type="text" name="preco_por_video" data-mask="money"
                               value="{{ $album->preco_por_video !== null ? number_format((float) $album->preco_por_video, 2, '.', '') : '' }}"
                               class="form-control" id="alb-preco-video"
                               {{ $album->preco_por_video === null ? 'disabled' : '' }}>
                        <div class="form-check form-check-sm mt-1">
                            <input class="form-check-input" type="checkbox" value="1" id="alb-herdar-preco"
                                   {{ $album->preco_por_video === null ? 'checked' : '' }}>
                            <label class="form-check-label small text-muted" for="alb-herdar-preco">
                                Herdar do evento (<span id="alb-preco-evento-preview">R$ 0,00</span>)
                            </label>
                        </div>
                        <div class="invalid-feedback" data-field="preco_por_video"></div>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label class="form-label small">Status</label>
                        <select name="status" class="form-select">
                            <option value="rascunho" @selected($album->status === 'rascunho')>Rascunho</option>
                            <option value="publicado" @selected($album->status === 'publicado')>Publicado</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            {{-- Barra de ação --}}
            <div class="panda-card mb-4 position-sticky" style="top: 90px;">
                <button type="submit" class="btn btn-dark-panda w-100 py-2 fw-bold">
                    <i class="bi bi-check-lg me-1"></i>Salvar alterações
                </button>
                <div class="text-center small text-muted mt-2" id="alb-save-status">—</div>

                <hr class="my-3">

                <div class="d-grid gap-2">
                    <a href="{{ route('painel.albuns.enviar', $album) }}" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-film me-1"></i> Gerenciar vídeos ({{ $album->videos()->count() }})
                    </a>
                    <button type="button" class="btn btn-outline-danger btn-sm" id="alb-delete"
                            data-url="{{ route('painel.albuns.destroy', $album) }}">
                        <i class="bi bi-trash me-1"></i> Excluir álbum
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection

@push('scripts')
    @vite('resources/js/pages/painel/albuns-editar.js')
@endpush
