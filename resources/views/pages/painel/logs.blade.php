@extends('theme::layouts.painel')

@section('titulo', 'Logs de erro')

@section('conteudo')
<x-theme::page-header titulo="Logs de erro" subtitulo="Diagnóstico de falhas em vídeos, jobs e log geral do Laravel" />

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><x-theme::stat-card label="Erros pipeline (24h)" value="{{ $contadores['pipeline_erros_24h'] }}" icon="bi-activity" color="danger" /></div>
    <div class="col-6 col-md-3"><x-theme::stat-card label="Vídeos travados"    value="{{ $contadores['videos_travados'] }}" icon="bi-hourglass-split" color="warning" /></div>
    <div class="col-6 col-md-3"><x-theme::stat-card label="Vídeos com erro"    value="{{ $contadores['videos_erro'] }}"     icon="bi-x-octagon"    color="danger"  /></div>
    <div class="col-6 col-md-3"><x-theme::stat-card label="Failed jobs (fila)" value="{{ $contadores['failed_jobs'] }}"     icon="bi-lightning-charge" color="warning" /></div>
</div>

<div class="panda-card p-0 overflow-hidden">
    <ul class="nav nav-tabs px-3 pt-3" id="logsTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-pipeline" type="button">
                <i class="bi bi-activity me-1"></i>Pipeline (vídeo)
                <span class="badge bg-danger-subtle text-danger-emphasis ms-1">{{ $contadores['pipeline_erros_24h'] }}</span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-videos-erro" type="button">
                <i class="bi bi-x-octagon me-1 text-danger"></i>Vídeos com erro
                <span class="badge bg-danger-subtle text-danger-emphasis ms-1">{{ $contadores['videos_erro'] }}</span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-videos-travados" type="button">
                <i class="bi bi-hourglass-split me-1 text-warning"></i>Travados
                <span class="badge bg-warning-subtle text-warning-emphasis ms-1">{{ $contadores['videos_travados'] }}</span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-failed-jobs" type="button">
                <i class="bi bi-lightning-charge me-1"></i>Failed jobs
                <span class="badge bg-warning-subtle text-warning-emphasis ms-1">{{ $contadores['failed_jobs'] }}</span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-laravel-log" type="button">
                <i class="bi bi-terminal me-1"></i>laravel.log
            </button>
        </li>
    </ul>

    <div class="tab-content p-3">
        {{-- ===== Pipeline (logs_processamento em DB — retenção 7 dias) ===== --}}
        <div class="tab-pane fade show active" id="tab-pipeline">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <p class="small text-muted mb-0">
                    Eventos do pipeline gravados em <code>logs_processamento</code>.
                    Retenção: <strong>7 dias</strong> (prune diário).
                </p>
                <button type="button" id="btn-pipeline-limpar" class="btn btn-sm btn-outline-danger"
                        data-url="{{ route('painel.logs.pipeline.limpar') }}">
                    <i class="bi bi-trash me-1"></i>Limpar tudo agora
                </button>
            </div>
            <div class="table-responsive">
                <table id="tbl-pipeline" class="table table-hover align-middle w-100"
                       data-url="{{ route('painel.logs.pipeline') }}"
                       data-show-url="{{ url('/painel/logs/pipeline') }}">
                    <thead><tr>
                        <th style="width:80px;">Nível</th>
                        <th>Evento</th>
                        <th>Mensagem</th>
                        <th>Vídeo</th>
                        <th>Cliente</th>
                        <th style="width:130px;">Quando</th>
                        <th class="text-end">Ações</th>
                    </tr></thead>
                </table>
            </div>
        </div>

        {{-- ===== Vídeos com erro ===== --}}
        <div class="tab-pane fade" id="tab-videos-erro">
            <p class="small text-muted">Vídeos com status <code>falhou</code> e mensagem de erro.</p>
            <div class="table-responsive">
                <table id="tbl-videos-erro" class="table table-hover align-middle w-100"
                       data-url="{{ route('painel.logs.videos-erro') }}"
                       data-reprocessar-url="{{ url('/painel/processamento') }}">
                    <thead><tr>
                        <th>Vídeo</th><th>Álbum</th><th>Cliente</th><th>Erro</th><th>Falhou</th><th class="text-end">Ações</th>
                    </tr></thead>
                </table>
            </div>
        </div>

        {{-- ===== Vídeos travados ===== --}}
        <div class="tab-pane fade" id="tab-videos-travados">
            <p class="small text-muted">
                Vídeos parados em <code>processando</code> há mais de 30 minutos.
                Provável causa: worker crashou mid-FFmpeg (OOM, kill -9, restart).
                Use "Reenfileirar" para marcar como pendente e voltar para a fila.
            </p>
            <div class="table-responsive">
                <table id="tbl-videos-travados" class="table table-hover align-middle w-100"
                       data-url="{{ route('painel.logs.videos-travados') }}"
                       data-resetar-url="{{ url('/painel/logs/videos-travados') }}">
                    <thead><tr>
                        <th>Vídeo</th><th>Álbum</th><th>Cliente</th><th>Preso desde</th><th class="text-end">Ações</th>
                    </tr></thead>
                </table>
            </div>
        </div>

        {{-- ===== Failed jobs ===== --}}
        <div class="tab-pane fade" id="tab-failed-jobs">
            <p class="small text-muted">
                Jobs da fila que falharam. Depois de investigar, remova para limpar a lista.
            </p>
            <div class="table-responsive">
                <table id="tbl-failed-jobs" class="table table-hover align-middle w-100"
                       data-url="{{ route('painel.logs.failed-jobs') }}"
                       data-base-url="{{ url('/painel/logs/failed-jobs') }}">
                    <thead><tr>
                        <th>Job</th><th>Fila</th><th>Erro</th><th>Falhou</th><th class="text-end">Ações</th>
                    </tr></thead>
                </table>
            </div>
        </div>

        {{-- ===== Laravel log ===== --}}
        <div class="tab-pane fade" id="tab-laravel-log">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <span class="small text-muted">
                        Últimas 500 linhas de <code id="log-arquivo">laravel-*.log</code>
                        · <span id="log-tamanho">—</span>
                        · <span class="text-muted">rotação diária, retenção 7 dias</span>
                    </span>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" id="btn-log-recarregar" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-clockwise me-1"></i>Recarregar
                    </button>
                    <button type="button" id="btn-log-limpar" class="btn btn-sm btn-outline-danger"
                            data-url="{{ route('painel.logs.laravel.limpar') }}">
                        <i class="bi bi-trash me-1"></i>Limpar log
                    </button>
                </div>
            </div>
            <pre id="log-conteudo" class="log-viewer">Carregando…</pre>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/painel/logs.js')
@endpush
