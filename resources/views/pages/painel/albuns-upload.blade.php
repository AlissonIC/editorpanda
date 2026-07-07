@extends('theme::layouts.painel')

@section('titulo', 'Enviar vídeos')

@section('conteudo')
<x-theme::page-header
    titulo="Enviar vídeos"
    subtitulo="{{ $album->evento?->nome ?? 'Álbum' }} · {{ $album->nome }}"
>
    <a href="{{ route('painel.albuns.index') }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</x-theme::page-header>

<div class="row g-4">
    <div class="col-lg-9">
        @if(! $temPlanoAtivo)
            {{-- Sem plano: bloqueia envios, mostra CTA claro --}}
            <div class="panda-card p-0 overflow-hidden">
                <div class="p-4 d-flex align-items-center gap-3 flex-wrap"
                     style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-radius: .75rem;">
                    <div class="d-inline-flex align-items-center justify-content-center"
                         style="width:52px;height:52px;border-radius:.6rem;background:rgba(255,255,255,.7);color:#d97706;font-size:1.5rem;">
                        <i class="bi bi-lock-fill"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h5 class="fw-bold mb-1">Envio bloqueado</h5>
                        <p class="mb-0 small">
                            Você não tem plano ativo. Assine um plano para enviar vídeos.
                            Seus vídeos existentes continuam disponíveis abaixo.
                        </p>
                    </div>
                    <a href="{{ route('painel.assinatura.index') }}" class="btn btn-dark">
                        <i class="bi bi-award me-1"></i> Escolher plano
                    </a>
                </div>
            </div>
        @else
            {{-- Dropzone compacto: empilha em mobile --}}
            <div class="panda-card p-0 overflow-hidden">
                <div
                    id="dropzone"
                    class="dropzone dropzone-compact p-4"
                    data-init-url="{{ route('painel.albuns.videos.init', $album) }}"
                >
                    <div class="dz-icon">
                        <i class="bi bi-cloud-arrow-up"></i>
                    </div>
                    <div class="dz-text">
                        <h5 class="fw-bold mb-1">Arraste vídeos ou clique para adicionar</h5>
                        <p class="small text-muted mb-0">
                            MP4, MOV, MKV ou WEBM · envio em partes — até 20&nbsp;GB por arquivo
                        </p>
                    </div>
                    <button type="button" class="btn btn-dark-panda dz-btn" id="btn-select">
                        <i class="bi bi-plus-lg me-1"></i> Adicionar
                    </button>
                    <input type="file" id="file-input" class="d-none" accept="video/mp4,video/quicktime,video/x-matroska,video/webm" multiple>
                </div>
            </div>
        @endif

        {{-- Área unificada de vídeos --}}
        <div class="panda-card mt-4">
            <div class="pv-section-head">
                <div class="d-flex align-items-center gap-2">
                    <input type="checkbox" id="pv-select-all" class="form-check-input pv-select-all m-0" title="Selecionar tudo">
                    <h5 class="fw-bold mb-0">Vídeos</h5>
                    <span class="text-muted small" id="pv-counter">—</span>
                </div>
                <div class="pv-view-toggle btn-group" role="group" aria-label="Modo de visualização">
                    <button type="button" class="btn btn-sm" data-view="list" title="Lista">
                        <i class="bi bi-list-ul"></i>
                    </button>
                    <button type="button" class="btn btn-sm" data-view="grid" title="Grade">
                        <i class="bi bi-grid-3x3-gap-fill"></i>
                    </button>
                </div>
            </div>

            {{-- Barra de ações em massa (aparece com >=1 selecionado) --}}
            <div id="pv-bulk-bar" class="pv-bulk-bar d-none">
                <div>
                    <strong id="pv-bulk-count">0</strong> selecionado(s)
                    <button type="button" class="btn btn-sm btn-link py-0 d-none" id="pv-select-remaining"
                            data-ids-url="{{ route('painel.albuns.videos.ids', $album) }}">
                        Selecionar todos os <span id="pv-total-count">0</span>
                    </button>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-link" id="pv-bulk-clear">
                        <i class="bi bi-x-circle me-1"></i>Limpar
                    </button>
                    <button type="button" class="btn btn-sm btn-danger" id="pv-bulk-delete">
                        <i class="bi bi-trash me-1"></i>Remover selecionados
                    </button>
                </div>
            </div>

            <div class="pv-scroll-area" id="pv-scroll">
                {{-- Fila em progresso --}}
                <div id="queue-wrap" class="pv-block d-none">
                    <div class="pv-block-title">Enviando agora</div>
                    <ul id="queue-list" class="pv-view-list list-unstyled m-0"></ul>
                </div>

                {{-- Vídeos já enviados --}}
                <div id="videos-wrap" class="pv-block">
                    <div class="pv-block-title">Enviados</div>
                    <ul id="videos-list" class="pv-view-list list-unstyled m-0">
                        <li class="text-muted small py-3">Carregando…</li>
                    </ul>

                    {{-- Sentinel do infinite scroll --}}
                    <div id="pv-sentinel" class="pv-sentinel d-none">
                        <div class="spinner-border spinner-border-sm text-secondary"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-3">
        <div
            id="storage-widget"
            class="panda-card mb-3"
            data-list-url="{{ route('painel.albuns.videos.list', $album) }}"
        >
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h6 class="fw-bold mb-0">Armazenamento</h6>
                @if(auth()->user()->plano)
                    <span class="small text-muted">Plano {{ auth()->user()->plano->nome }}</span>
                @endif
            </div>
            <div class="d-flex align-items-baseline gap-1 mb-2">
                <span class="fs-5 fw-bold" id="sw-usado">…</span>
                <span class="text-muted small" id="sw-limite"></span>
            </div>
            <div class="progress" style="height: 8px;">
                <div id="sw-bar" class="progress-bar bg-success" style="width: 0%"></div>
            </div>
            <p class="small text-muted mt-2 mb-0" id="sw-hint">Consultando uso…</p>
        </div>

        <div class="panda-card">
            <h6 class="fw-bold mb-3">Destino do armazenamento</h6>
            <div class="d-flex align-items-center gap-3">
                <div class="storage-badge {{ $disco === 's3' ? 'bg-info-subtle text-info-emphasis' : 'bg-success-subtle text-success-emphasis' }}">
                    <i class="bi {{ $disco === 's3' ? 'bi-cloud' : 'bi-hdd' }}"></i>
                </div>
                <div>
                    <div class="fw-semibold">
                        {{ $disco === 's3' ? 'Amazon S3' : 'Local' }}
                    </div>
                    <small class="text-muted">Disco vigente</small>
                </div>
            </div>

            @if(auth()->user()->isAdmin())
                <a href="{{ route('painel.configuracoes.index') }}" class="btn btn-link px-0 mt-2 small">
                    <i class="bi bi-gear me-1"></i> Alterar
                </a>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/painel/albuns-upload.js')
@endpush
