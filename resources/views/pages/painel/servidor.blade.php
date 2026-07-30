@extends('theme::layouts.painel')

@section('titulo', 'Servidor')

@php
    // Formatador em pt-BR pra tempo médio
    $tm = $tempoMedioSegundos;
    if ($tm <= 0)      $tempoMedioLabel = '—';
    elseif ($tm < 60)  $tempoMedioLabel = round($tm) . 's';
    elseif ($tm < 3600) $tempoMedioLabel = round($tm / 60, 1) . ' min';
    else               $tempoMedioLabel = round($tm / 3600, 1) . ' h';

    $chartMax = max(1, ...$serieDias->pluck('total')->all());
@endphp

@section('conteudo')
<x-theme::page-header
    titulo="Relatório do servidor"
    subtitulo="Métricas operacionais: processamento, storage, assinantes"
/>

{{-- ===== Bloco 1: Processamento de vídeo ===== --}}
<h6 class="fw-bold text-uppercase small text-muted mb-2">Processamento</h6>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <x-theme::stat-card label="Vídeos totais" value="{{ number_format($videosTotal, 0, ',', '.') }}" icon="bi-collection-play" color="dark" />
    </div>
    <div class="col-6 col-md-3">
        <x-theme::stat-card label="Processados (24h)" value="{{ number_format($processados24h, 0, ',', '.') }}" icon="bi-check-circle" color="success" />
    </div>
    <div class="col-6 col-md-3">
        <x-theme::stat-card label="Processados (7d)" value="{{ number_format($processados7d, 0, ',', '.') }}" icon="bi-graph-up" color="info" />
    </div>
    <div class="col-6 col-md-3">
        <x-theme::stat-card label="Tempo médio" value="{{ $tempoMedioLabel }}" icon="bi-stopwatch" color="primary" />
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-lg-7">
        <div class="panda-card">
            <h6 class="fw-bold mb-3">Estado atual da fila</h6>
            <div class="row g-3">
                <div class="col-6 col-md-3">
                    <div class="p-3 rounded" style="background:#eef1f6;">
                        <div class="small text-muted">Enviando</div>
                        <div class="h4 fw-bold mb-0">{{ $videosEnviando }}</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-3 rounded" style="background:#fef3c7;">
                        <div class="small text-muted">Pendentes</div>
                        <div class="h4 fw-bold mb-0">{{ $videosPendentes }}</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-3 rounded" style="background:#dbeafe;">
                        <div class="small text-muted">Processando</div>
                        <div class="h4 fw-bold mb-0">{{ $videosProcessando }}</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-3 rounded" style="background:{{ $videosFalhados > 0 ? '#fee2e2' : '#f3f4f6' }};">
                        <div class="small text-muted">Falharam</div>
                        <div class="h4 fw-bold mb-0 {{ $videosFalhados > 0 ? 'text-danger' : '' }}">{{ $videosFalhados }}</div>
                    </div>
                </div>
            </div>
            <div class="mt-3 small text-muted">
                <i class="bi bi-info-circle me-1"></i>
                Concluídos totais: <strong>{{ number_format($videosConcluidos, 0, ',', '.') }}</strong>
                · últimos 30d: <strong>{{ number_format($processados30d, 0, ',', '.') }}</strong>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="panda-card h-100">
            <h6 class="fw-bold mb-3">Processados por dia (últimos 7)</h6>
            <div class="pv-mini-chart">
                @foreach($serieDias as $dia)
                    <div class="pv-mini-chart-col" title="{{ $dia['label'] }}: {{ $dia['total'] }}">
                        <div class="pv-mini-chart-bar" style="height: {{ max(4, round($dia['total'] * 100 / $chartMax)) }}%;">
                            <span class="pv-mini-chart-val">{{ $dia['total'] }}</span>
                        </div>
                        <div class="pv-mini-chart-label">{{ $dia['label'] }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

{{-- ===== Bloco 2: Storage + Assinantes ===== --}}
<h6 class="fw-bold text-uppercase small text-muted mb-2">Storage & assinantes</h6>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <x-theme::stat-card label="Storage usado" value="{{ number_format($storageGb, 2, ',', '.') }} GB" icon="bi-hdd" color="warning" />
    </div>
    <div class="col-6 col-md-3">
        <x-theme::stat-card label="Reservado (quota)" value="{{ number_format($storageReservadoGb, 2, ',', '.') }} GB" icon="bi-hdd-fill" color="secondary" />
    </div>
    <div class="col-6 col-md-3">
        <x-theme::stat-card label="Assinantes ativos" value="{{ $assinantesAtivos }} / {{ $usuariosTotal }}" icon="bi-people-fill" color="success" />
    </div>
    <div class="col-6 col-md-3">
        <x-theme::stat-card label="Expirando (7d)" value="{{ $assinantesExpirando7d }}" icon="bi-hourglass-split" color="{{ $assinantesExpirando7d > 0 ? 'warning' : 'secondary' }}" />
    </div>
</div>

{{-- ===== Bloco 3: Conteúdo publicado + vendas do mês ===== --}}
<h6 class="fw-bold text-uppercase small text-muted mb-2">Conteúdo & vendas do mês</h6>
<div class="row g-3">
    <div class="col-6 col-md-3">
        <x-theme::stat-card label="Eventos ativos" value="{{ $eventosAtivos }} / {{ $eventosTotal }}" icon="bi-calendar-event" color="info" />
    </div>
    <div class="col-6 col-md-3">
        <x-theme::stat-card label="Álbuns publicados" value="{{ $albunsPublicados }} / {{ $albunsTotal }}" icon="bi-images" color="primary" />
    </div>
    <div class="col-6 col-md-3">
        <x-theme::stat-card label="Vendas do mês" value="R$ {{ number_format($vendasMes, 2, ',', '.') }}" icon="bi-cash-stack" color="success" />
    </div>
    <div class="col-6 col-md-3">
        <x-theme::stat-card label="Pedidos do mês" value="{{ $pedidosMes }}" icon="bi-receipt" color="dark" />
    </div>
</div>
@endsection
