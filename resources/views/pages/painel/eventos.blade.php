@php $isAdmin = auth()->user()->isAdmin(); @endphp
@extends('theme::layouts.painel')

@section('titulo', 'Eventos')

@section('conteudo')
<x-theme::page-header
    titulo="{{ $isAdmin ? 'Gerenciar Eventos' : 'Meus Eventos' }}"
    subtitulo="{{ $isAdmin ? 'Eventos cadastrados pelos clientes' : 'Organize seus eventos para agrupar álbuns' }}"
>
    @unless($isAdmin)
        <button type="button" class="btn btn-dark-panda" data-bs-toggle="modal" data-bs-target="#modalEvento" id="btn-novo">
            <i class="bi bi-plus-lg me-1"></i> Novo Evento
        </button>
    @endunless
</x-theme::page-header>

<div class="panda-card">
    <div class="table-responsive">
        <table id="tbl-eventos" class="table table-hover align-middle w-100">
            <thead>
                <tr>
                    <th>Evento</th>
                    @if($isAdmin)<th>Cliente</th>@endif
                    <th>Localização</th>
                    <th>Data</th>
                    <th>Status</th>
                    <th>Álbuns</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
        </table>
    </div>
</div>

@unless($isAdmin)
<div class="modal fade" id="modalEvento" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="form-evento" class="modal-content" novalidate>
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Novo Evento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id">

                <ul class="nav nav-tabs mb-3" id="eventoTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-info" type="button">
                            <i class="bi bi-info-circle me-1"></i>Informações
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-processamento" type="button">
                            <i class="bi bi-magic me-1"></i>Processamento
                        </button>
                    </li>
                </ul>

                <div class="tab-content">
                    {{-- ===== Aba: Informações ===== --}}
                    <div class="tab-pane fade show active" id="tab-info">
                        <div class="mb-3">
                            <label class="form-label small">Nome do evento</label>
                            <input type="text" name="nome" class="form-control" required>
                            <div class="invalid-feedback" data-field="nome"></div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-7 mb-3">
                                <label class="form-label small">Cidade</label>
                                <input type="text" name="localizacao_cidade" class="form-control">
                            </div>
                            <div class="col-md-5 mb-3">
                                <label class="form-label small">Estado</label>
                                @include('theme::partials.select-estado', ['name' => 'localizacao_estado'])
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4 mb-3">
                                <label class="form-label small">Data</label>
                                <input type="date" name="data" class="form-control">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label small">Preço por vídeo</label>
                                <input type="text" name="preco_por_video" data-mask="money" value="10.00" class="form-control" required>
                                <small class="text-muted">Padrão para álbuns deste evento.</small>
                                <div class="invalid-feedback" data-field="preco_por_video"></div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label small">Status</label>
                                <select name="status" class="form-select">
                                    <option value="ativo">Ativo</option>
                                    <option value="inativo">Inativo</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    {{-- ===== Aba: Processamento ===== --}}
                    <div class="tab-pane fade" id="tab-processamento">
                        <p class="small text-muted">
                            Todos os vídeos deste evento serão processados no formato Instagram (9:16).
                            Vídeos em modo paisagem recebem zoom automático para preencher o quadro.
                        </p>

                        <div class="ev-logo-block mb-3">
                            <div class="d-flex align-items-center gap-3">
                                <div class="ev-logo-preview" id="ev-logo-preview">
                                    <i class="bi bi-image"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="fw-semibold small mb-1">Logo do evento</div>
                                    <p class="text-muted small mb-2">PNG com transparência recomendado, até 2&nbsp;MB.</p>
                                    <div class="d-flex gap-2">
                                        <label class="btn btn-sm btn-dark-panda">
                                            <i class="bi bi-upload me-1"></i>Escolher arquivo
                                            <input type="file" id="ev-logo-input" class="d-none"
                                                   accept="image/png,image/jpeg,image/webp,image/svg+xml">
                                        </label>
                                        <button type="button" class="btn btn-sm btn-outline-danger d-none" id="ev-logo-remove">
                                            <i class="bi bi-x-lg"></i>Remover
                                        </button>
                                    </div>
                                    <small class="text-warning d-none" id="ev-logo-hint">Salve o evento antes de enviar o logo.</small>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6 mb-3">
                                <label class="form-label small">Posição do logo</label>
                                <div class="pos-grid" data-field-target="logo_posicao">
                                    <button type="button" class="pos-cell" data-value="top-left" title="Superior esquerdo"></button>
                                    <button type="button" class="pos-cell" data-value="top-right" title="Superior direito"></button>
                                    <button type="button" class="pos-cell" data-value="center" title="Centro"></button>
                                    <button type="button" class="pos-cell" data-value="bottom-left" title="Inferior esquerdo"></button>
                                    <button type="button" class="pos-cell" data-value="bottom-right" title="Inferior direito"></button>
                                </div>
                                <input type="hidden" name="logo_posicao" value="top-right">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label small">
                                    Escala do logo (<span id="ev-scale-val">15</span>% da largura)
                                </label>
                                <input type="range" name="logo_escala" min="0.05" max="0.5" step="0.01"
                                       value="0.15" class="form-range" id="ev-scale-range">
                            </div>
                        </div>

                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="gradiente_habilitado" value="1" id="ev-grad">
                            <label class="form-check-label" for="ev-grad">
                                Adicionar gradiente escuro atrás do logo
                                <small class="text-muted d-block">Aumenta contraste em qualquer tipo de vídeo.</small>
                            </label>
                        </div>

                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="rosto_centralizar" value="1" id="ev-face">
                            <label class="form-check-label" for="ev-face">
                                Centralizar rosto principal <span class="badge bg-warning text-dark">em breve</span>
                                <small class="text-muted d-block">Move dinamicamente o crop para manter o rosto em cena (feature futura).</small>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-dark-panda">Salvar</button>
            </div>
        </form>
    </div>
</div>
@endunless
@endsection

@push('scripts')
    @vite('resources/js/pages/painel/eventos.js')
@endpush
