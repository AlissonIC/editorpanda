@extends('theme::layouts.painel')

@section('titulo', 'Editar evento — ' . $evento->nome)

@section('conteudo')
<x-theme::page-header
    titulo="Editar evento"
    subtitulo="{{ $evento->nome }}"
>
    <a href="{{ route('painel.eventos.index') }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
    <a href="{{ route('publico.evento.show', $evento->slug) }}" target="_blank" class="btn btn-outline-secondary">
        <i class="bi bi-box-arrow-up-right me-1"></i> Ver página pública
    </a>
</x-theme::page-header>

<form id="form-evento-editar" data-url="{{ route('painel.eventos.update', $evento) }}" novalidate>
    @csrf
    <div class="row g-4">
        <div class="col-lg-8">
            {{-- Informações básicas --}}
            <div class="panda-card mb-4">
                <h5 class="fw-bold mb-3">Informações</h5>
                <div class="mb-3">
                    <label class="form-label small">Nome do evento</label>
                    <input type="text" name="nome" value="{{ $evento->nome }}" class="form-control" required>
                    <div class="invalid-feedback" data-field="nome"></div>
                </div>
                <div class="row g-3">
                    <div class="col-md-7 mb-3">
                        <label class="form-label small">Cidade</label>
                        <input type="text" name="localizacao_cidade" value="{{ $evento->localizacao_cidade }}" class="form-control">
                    </div>
                    <div class="col-md-5 mb-3">
                        <label class="form-label small">Estado</label>
                        @include('theme::partials.select-estado', ['name' => 'localizacao_estado', 'selected' => $evento->localizacao_estado])
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-4 mb-3">
                        <label class="form-label small">Data</label>
                        <input type="date" name="data" value="{{ optional($evento->data)->format('Y-m-d') }}" class="form-control">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label small">Preço por vídeo</label>
                        <input type="text" name="preco_por_video" data-mask="money" value="{{ number_format((float) $evento->preco_por_video, 2, '.', '') }}" class="form-control" required id="ev-preco">
                        <div class="form-check form-check-sm mt-1">
                            <input class="form-check-input" type="checkbox" value="1" id="ev-gratuito" {{ $evento->ehGratuito() ? 'checked' : '' }}>
                            <label class="form-check-label small text-muted" for="ev-gratuito">
                                Evento gratuito (usa 0,00)
                            </label>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label small">Status</label>
                        <select name="status" class="form-select">
                            <option value="ativo" @selected($evento->status === 'ativo')>Ativo</option>
                            <option value="inativo" @selected($evento->status === 'inativo')>Inativo</option>
                        </select>
                    </div>
                </div>
            </div>

            {{-- Descrição --}}
            <div class="panda-card mb-4">
                <h5 class="fw-bold mb-1">Descrição</h5>
                <p class="text-muted small mb-3">Aparece na página pública do evento, acima dos álbuns.</p>
                <textarea name="descricao" class="form-control" rows="5"
                          maxlength="5000"
                          placeholder="Conte sobre o evento, local, fotógrafo…">{{ $evento->descricao }}</textarea>
            </div>

            {{-- Processamento --}}
            <div class="panda-card mb-4">
                <h5 class="fw-bold mb-1">Processamento</h5>
                <p class="text-muted small mb-3">
                    Todos os vídeos deste evento serão processados no formato Instagram (9:16).
                    O preview ao lado atualiza em tempo real.
                </p>

                <div class="row g-4">
                    {{-- Coluna esquerda: controles --}}
                    <div class="col-md-7">
                        <div class="ev-controls-box">
                            <div class="ev-controls-section">
                                <div class="ev-controls-section-label">Logo</div>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="ev-logo-preview {{ $evento->logo_url ? 'has-logo' : '' }}" id="ev-logo-preview"
                                         style="{{ $evento->logo_url ? 'background-image:url('.$evento->logo_url.')' : '' }}">
                                        @unless($evento->logo_url)<i class="bi bi-image"></i>@endunless
                                    </div>
                                    <div class="flex-grow-1 min-w-0">
                                        <p class="text-muted small mb-2 lh-sm">PNG com transparência, até 2&nbsp;MB.</p>
                                        <div class="d-flex gap-2">
                                            <label class="btn btn-sm btn-dark-panda">
                                                <i class="bi bi-upload me-1"></i>Escolher
                                                <input type="file" id="ev-logo-input" class="d-none"
                                                       accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                                       data-url="{{ route('painel.eventos.logo.upload', $evento) }}"
                                                       data-delete-url="{{ route('painel.eventos.logo.delete', $evento) }}">
                                            </label>
                                            <button type="button" class="btn btn-sm btn-outline-danger {{ $evento->logo_url ? '' : 'd-none' }}" id="ev-logo-remove">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="ev-controls-section">
                                <div class="ev-controls-section-label">Posição</div>
                                <div class="pos-grid" data-field-target="logo_posicao">
                                    @foreach(App\Models\Evento::POSICOES_LOGO as $pos)
                                        <button type="button"
                                                class="pos-cell {{ ($evento->logo_posicao ?? 'top-right') === $pos ? 'is-active' : '' }}"
                                                data-value="{{ $pos }}"
                                                title="{{ str_replace('-', ' ', $pos) }}"></button>
                                    @endforeach
                                </div>
                                <input type="hidden" name="logo_posicao" value="{{ $evento->logo_posicao ?? 'top-right' }}">
                            </div>

                            <div class="ev-controls-section">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="ev-controls-section-label mb-0">Escala</div>
                                    <span class="badge bg-light text-dark border"><span id="ev-scale-val">{{ (int) round(($evento->logo_escala ?? 0.15) * 100) }}</span>%</span>
                                </div>
                                <input type="range" name="logo_escala" min="0.05" max="0.5" step="0.01"
                                       value="{{ $evento->logo_escala ?? 0.15 }}" class="form-range mt-2 mb-0" id="ev-scale-range">
                            </div>

                            <div class="ev-controls-section ev-controls-section--toggles">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" name="gradiente_habilitado" value="1" id="ev-grad" {{ $evento->gradiente_habilitado ? 'checked' : '' }}>
                                    <label class="form-check-label small" for="ev-grad">
                                        Gradiente escuro atrás do logo
                                    </label>
                                </div>

                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" name="rosto_centralizar" value="1" id="ev-face" {{ $evento->rosto_centralizar ? 'checked' : '' }}>
                                    <label class="form-check-label small" for="ev-face">
                                        Centralizar rosto <span class="badge bg-warning text-dark">em breve</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Coluna direita: preview em celular estilo Reels --}}
                    <div class="col-md-5">
                        <div class="reels-phone mx-auto">
                            <div class="reels-phone-notch"></div>
                            <div class="reels-screen" style="background-image: url('{{ asset('img/reels-preview.jpg') }}');">
                                <div class="reels-gradient {{ $evento->gradiente_habilitado ? '' : 'd-none' }}"
                                     id="reels-gradient"
                                     data-position="{{ $evento->logo_posicao ?? 'top-right' }}"></div>

                                <div class="reels-logo {{ $evento->logo_url ? '' : 'd-none' }}"
                                     id="reels-logo"
                                     data-position="{{ $evento->logo_posicao ?? 'top-right' }}"
                                     style="width: {{ (($evento->logo_escala ?? 0.15) * 100) }}%; @if($evento->logo_url) background-image: url('{{ $evento->logo_url }}?v={{ time() }}'); @endif">
                                </div>

                                {{-- UI decorativa do Reels --}}
                                <div class="reels-ui-right">
                                    <div class="reels-ui-icon"><i class="bi bi-heart-fill"></i></div>
                                    <div class="reels-ui-icon"><i class="bi bi-chat-fill"></i></div>
                                    <div class="reels-ui-icon"><i class="bi bi-send-fill"></i></div>
                                    <div class="reels-ui-icon"><i class="bi bi-three-dots"></i></div>
                                </div>
                                <div class="reels-ui-bottom">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <div class="reels-avatar"></div>
                                        <div class="fw-semibold small">{{ '@' . \Illuminate\Support\Str::slug($evento->user->nome ?? 'evento', '') }}</div>
                                    </div>
                                    <div class="reels-caption text-truncate">{{ $evento->nome }}</div>
                                </div>
                            </div>
                            <div class="reels-phone-indicator"></div>
                        </div>
                        <p class="text-center small text-muted mt-2 mb-0">
                            Preview simulado — reflete<br>as configurações no vídeo final
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            {{-- Capa do evento --}}
            <div class="panda-card mb-4">
                <h5 class="fw-bold mb-1">Capa do evento</h5>
                <p class="text-muted small mb-3">Imagem que aparece no topo da página pública.</p>

                <div class="ev-capa-preview mb-3 {{ $evento->capa_url ? 'has-capa' : '' }}" id="ev-capa-preview"
                     style="{{ $evento->capa_url ? 'background-image:url('.$evento->capa_url.')' : '' }}"
                     data-url="{{ route('painel.eventos.capa.upload', $evento) }}"
                     data-delete-url="{{ route('painel.eventos.capa.delete', $evento) }}">
                    @unless($evento->capa_url)<i class="bi bi-image"></i>@endunless
                </div>

                <div class="d-flex gap-2">
                    <label class="btn btn-sm btn-dark-panda flex-grow-1">
                        <i class="bi bi-upload me-1"></i>{{ $evento->capa_url ? 'Trocar' : 'Escolher imagem' }}
                        <input type="file" id="ev-capa-input" class="d-none" accept="image/png,image/jpeg,image/webp">
                    </label>
                    <button type="button" class="btn btn-sm btn-outline-danger {{ $evento->capa_url ? '' : 'd-none' }}" id="ev-capa-remove">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
                <small class="text-muted d-block mt-2">JPG, PNG ou WEBP · até 4&nbsp;MB</small>
            </div>

            {{-- Barra de ação --}}
            <div class="panda-card mb-4 position-sticky" style="top: 90px;">
                <button type="submit" class="btn btn-dark-panda w-100 py-2 fw-bold">
                    <i class="bi bi-check-lg me-1"></i>Salvar alterações
                </button>
                <div class="text-center small text-muted mt-2" id="ev-save-status">—</div>
            </div>
        </div>
    </div>
</form>

{{-- Álbuns vinculados --}}
<div class="panda-card mt-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h5 class="fw-bold mb-0">Álbuns deste evento</h5>
            <small class="text-muted">{{ $evento->albuns->count() }} álbum(ns) cadastrado(s)</small>
        </div>
        <button type="button" class="btn btn-dark-panda" data-bs-toggle="modal" data-bs-target="#modalAlbumEvento">
            <i class="bi bi-plus-lg me-1"></i> Novo álbum
        </button>
    </div>

    <div class="row g-3" id="albuns-lista">
        @forelse($evento->albuns as $album)
            <div class="col-md-6 col-lg-4">
                <div class="album-mini-card">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <a href="{{ route('painel.albuns.edit', $album) }}" class="fw-semibold text-decoration-none link-row text-truncate" style="max-width: 65%;">{{ $album->nome }}</a>
                        @if($album->ehGratuito())
                            <span class="badge bg-success-subtle text-success-emphasis">Grátis</span>
                        @else
                            <span class="badge bg-secondary-subtle text-secondary-emphasis">R$ {{ number_format($album->precoEfetivoPorVideo(), 2, ',', '.') }}/vídeo</span>
                        @endif
                    </div>
                    @if($album->subtitulo)
                        <div class="small text-muted text-truncate mb-2">{{ $album->subtitulo }}</div>
                    @endif
                    <div class="small text-muted mb-3">
                        <i class="bi bi-film me-1"></i>{{ $album->videos_count }} vídeo(s) ·
                        <span class="status-badge {{ $album->status }}">{{ ucfirst($album->status) }}</span>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="{{ route('painel.albuns.enviar', $album) }}" class="btn btn-sm btn-outline-secondary flex-grow-1">
                            <i class="bi bi-upload me-1"></i>Vídeos
                        </a>
                        <a href="{{ route('painel.albuns.edit', $album) }}" class="btn btn-sm btn-outline-primary" title="Editar álbum">
                            <i class="bi bi-pencil"></i>
                        </a>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12 text-center py-4 text-muted small">
                Nenhum álbum ainda. Clique em "Novo álbum" para começar.
            </div>
        @endforelse
    </div>
</div>

{{-- Modal criar álbum inline --}}
<div class="modal fade" id="modalAlbumEvento" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="form-album-evento" class="modal-content" novalidate>
            @csrf
            <input type="hidden" name="evento_id" value="{{ $evento->id }}">
            <div class="modal-header">
                <h5 class="modal-title">Novo álbum</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small">Nome</label>
                    <input type="text" name="nome" class="form-control" required>
                    <div class="invalid-feedback" data-field="nome"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Subtítulo</label>
                    <input type="text" name="subtitulo" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label small">Descrição</label>
                    <textarea name="descricao" class="form-control" rows="2"></textarea>
                </div>
                <div class="row g-3">
                    <div class="col-md-4 mb-3">
                        <label class="form-label small">Preço fixo do álbum</label>
                        <input type="text" name="preco" data-mask="money" value="0.00" class="form-control" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label small">Preço por vídeo</label>
                        <input type="text" name="preco_por_video" data-mask="money" class="form-control" id="album-preco-video-inline" disabled>
                        <div class="form-check form-check-sm mt-1">
                            <input class="form-check-input" type="checkbox" value="1" id="album-herdar-preco-inline" checked>
                            <label class="form-check-label small text-muted" for="album-herdar-preco-inline">
                                Herdar do evento (R$ {{ number_format($evento->precoEfetivoPorVideo(), 2, ',', '.') }})
                            </label>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label small">Status</label>
                        <select name="status" class="form-select">
                            <option value="rascunho">Rascunho</option>
                            <option value="publicado">Publicado</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-dark-panda">Criar álbum</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/painel/eventos-editar.js')
@endpush
