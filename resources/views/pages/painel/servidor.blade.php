@extends('theme::layouts.painel')

@section('titulo', 'Servidor')

@php
    $fmtTempo = function ($seg) {
        if ($seg <= 0)   return '—';
        if ($seg < 60)   return round($seg) . 's';
        if ($seg < 3600) return round($seg / 60, 1) . ' min';
        return round($seg / 3600, 1) . ' h';
    };

    $brl = fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
    $totalReceitaRange = $totalVendasRange + $totalAssinaturasRange;
@endphp

@push('scripts')
    {{-- Chart.js via CDN — evita adicionar dependência npm só pra essa tela.
         Versão 4 UMD é compatível com todos os browsers modernos. --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
@endpush

@section('conteudo')
<x-theme::page-header
    titulo="Relatório do servidor"
    subtitulo="Métricas operacionais no período selecionado"
/>

{{-- ===== Filtro de range de datas ===== --}}
<form method="get" class="panda-card mb-4 d-flex flex-wrap align-items-end gap-3">
    <div>
        <label class="form-label small mb-1">De</label>
        <input type="date" name="de" value="{{ $de }}" class="form-control form-control-sm" style="width: 160px;">
    </div>
    <div>
        <label class="form-label small mb-1">Até</label>
        <input type="date" name="ate" value="{{ $ate }}" class="form-control form-control-sm" style="width: 160px;">
    </div>
    <div class="d-flex gap-2 align-items-center">
        <button type="submit" class="btn btn-dark-panda btn-sm">
            <i class="bi bi-funnel me-1"></i>Aplicar
        </button>
        <div class="btn-group btn-group-sm" role="group">
            <a href="?de={{ now()->subDays(7)->format('Y-m-d') }}&ate={{ now()->format('Y-m-d') }}" class="btn btn-outline-secondary">7d</a>
            <a href="?de={{ now()->subDays(30)->format('Y-m-d') }}&ate={{ now()->format('Y-m-d') }}" class="btn btn-outline-secondary">30d</a>
            <a href="?de={{ now()->subDays(90)->format('Y-m-d') }}&ate={{ now()->format('Y-m-d') }}" class="btn btn-outline-secondary">90d</a>
            <a href="?de={{ now()->subDays(365)->format('Y-m-d') }}&ate={{ now()->format('Y-m-d') }}" class="btn btn-outline-secondary">1a</a>
        </div>
    </div>
    <div class="ms-auto small text-muted">
        <i class="bi bi-calendar-range me-1"></i>
        <strong>{{ $diasNoRange }}</strong> dia(s) · agrupamento
        <strong>{{ $granularidade === 'semana' ? 'semanal' : 'diário' }}</strong>
    </div>
</form>

{{-- ===== Grande chart de receitas ===== --}}
<div class="panda-card mb-4">
    <div class="d-flex justify-content-between align-items-start flex-wrap mb-3">
        <div>
            <h6 class="fw-bold mb-1">Receita no período</h6>
            <div class="small text-muted">Vendas de vídeos (compradores) vs Assinaturas do sistema (fotógrafos)</div>
        </div>
        <div class="text-end">
            <div class="h4 fw-bold mb-0">{{ $brl($totalReceitaRange) }}</div>
            <div class="small text-muted">Total no período</div>
        </div>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-6">
            <div class="p-3 rounded" style="background:#dbeafe;">
                <div class="small text-muted">
                    <span class="d-inline-block me-1" style="width:10px;height:10px;background:#3b82f6;border-radius:2px;"></span>
                    Vendas de vídeos
                </div>
                <div class="h5 fw-bold mb-0">{{ $brl($totalVendasRange) }}</div>
            </div>
        </div>
        <div class="col-6">
            <div class="p-3 rounded" style="background:#ede9fe;">
                <div class="small text-muted">
                    <span class="d-inline-block me-1" style="width:10px;height:10px;background:#8b5cf6;border-radius:2px;"></span>
                    Assinaturas do sistema
                </div>
                <div class="h5 fw-bold mb-0">{{ $brl($totalAssinaturasRange) }}</div>
            </div>
        </div>
    </div>

    <div style="position: relative; height: 320px;">
        <canvas id="chart-receita"
                data-labels='@json(collect($serieVendas)->pluck('label')->all())'
                data-vendas='@json(collect($serieVendas)->pluck('valor')->all())'
                data-assinaturas='@json(collect($serieAssinaturas)->pluck('valor')->all())'
        ></canvas>
    </div>
</div>

{{-- ===== Processamento (respeita range) ===== --}}
<h6 class="fw-bold text-uppercase small text-muted mb-2">Processamento no período</h6>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <x-theme::stat-card label="Total processado" value="{{ number_format($processadosRange, 0, ',', '.') }}" icon="bi-check-circle" color="success" />
    </div>
    <div class="col-6 col-md-3">
        <x-theme::stat-card label="Tempo médio — Vídeos" value="{{ $fmtTempo($tempoMedioVideo) }}" icon="bi-film" color="primary" />
    </div>
    <div class="col-6 col-md-3">
        <x-theme::stat-card label="Tempo médio — Fotos" value="{{ $fmtTempo($tempoMedioImagem) }}" icon="bi-image" color="info" />
    </div>
    <div class="col-6 col-md-3">
        <x-theme::stat-card label="Falharam agora" value="{{ $videosFalhados }}" icon="bi-x-octagon" color="{{ $videosFalhados > 0 ? 'warning' : 'secondary' }}" />
    </div>
</div>

{{-- Onde o tempo foi gasto: encode vs espera, com distribuição --}}
<div class="panda-card mb-4">
    <div class="d-flex justify-content-between align-items-center mb-1">
        <h6 class="fw-bold mb-0">Tempo de processamento por arquivo</h6>
        <span class="small text-muted">{{ $tempos['amostra_total'] }} medição(ões) no período</span>
    </div>
    <p class="small text-muted mb-3">
        Os cards acima mostram o tempo <em>porta a porta</em> — do envio até ficar pronto.
        Aqui ele é separado: <strong>encode</strong> é o ffmpeg trabalhando,
        <strong>espera</strong> é upload + tempo parado na fila.
    </p>

    @php
        $blocos = [
            ['rotulo' => 'Vídeos', 'icone' => 'bi-film',  'd' => $tempos['video']],
            ['rotulo' => 'Fotos',  'icone' => 'bi-image', 'd' => $tempos['imagem']],
        ];
    @endphp

    <div class="row g-3">
        @foreach($blocos as $b)
            <div class="col-md-6">
                <div class="p-3 rounded h-100" style="background:#f7f8fa;">
                    <div class="fw-semibold mb-2">
                        <i class="bi {{ $b['icone'] }} me-1"></i>{{ $b['rotulo'] }}
                        <span class="text-muted fw-normal small">— {{ $b['d']['n'] }} arquivo(s)</span>
                    </div>

                    @if($b['d']['n'] === 0)
                        <div class="small text-muted">Nenhuma medição no período.</div>
                    @else
                        <div class="row g-2 text-center">
                            @foreach([
                                ['Mediana', $b['d']['mediana'], 'metade dos arquivos fica abaixo disso'],
                                ['Média', $b['d']['media'], 'sobe fácil com um arquivo longo no meio'],
                                ['p95', $b['d']['p95'], '19 de cada 20 ficam abaixo'],
                                ['Pior', $b['d']['max'], 'o mais demorado do período'],
                            ] as [$rot, $val, $dica])
                                <div class="col-3">
                                    <div class="small text-muted" title="{{ $dica }}">{{ $rot }}</div>
                                    <div class="fw-bold">{{ $fmtTempo($val) }}</div>
                                </div>
                            @endforeach
                        </div>

                        <hr class="my-2">
                        <div class="d-flex justify-content-between small">
                            <span class="text-muted">Espera média (upload + fila)</span>
                            <strong>{{ $b['d']['espera_media'] === null ? '—' : $fmtTempo($b['d']['espera_media']) }}</strong>
                        </div>
                        @if($b['d']['seg_por_min'] !== null)
                            <div class="d-flex justify-content-between small">
                                <span class="text-muted" title="Único número comparável entre períodos: a média sobe sozinha se os vídeos ficarem mais longos.">
                                    Custo por minuto de vídeo
                                </span>
                                <strong>{{ number_format($b['d']['seg_por_min'], 1, ',', '.') }}s / min</strong>
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    @if(! empty($tempos['piores']))
        <div class="mt-3">
            <div class="small text-muted mb-1">Os mais demorados do período</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small">
                    <tbody>
                    @foreach($tempos['piores'] as $p)
                        <tr>
                            <td class="text-muted" style="width:60px;">#{{ $p['id'] }}</td>
                            <td class="text-truncate" style="max-width:260px;">{{ $p['nome'] ?: '—' }}</td>
                            <td class="text-muted" style="width:110px;">
                                {{ $p['duracao'] > 0 ? $fmtTempo($p['duracao']) . ' de vídeo' : 'foto' }}
                            </td>
                            <td class="text-end fw-semibold" style="width:90px;">{{ $fmtTempo($p['seg']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

{{-- Detalhamento da fila (snapshot atual, não afeta pelo range) --}}
<div class="panda-card mb-4">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="fw-bold mb-0">Estado atual da fila</h6>
        <span class="small text-muted">snapshot agora — independe do período</span>
    </div>
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
            <div class="p-3 rounded" style="background:#f3f4f6;">
                <div class="small text-muted">Concluídos totais</div>
                <div class="h4 fw-bold mb-0">{{ number_format($videosConcluidos, 0, ',', '.') }}</div>
            </div>
        </div>
    </div>
    @if($totalVideoProcessado > 0 || $totalImagemProcessada > 0)
        <div class="small text-muted mt-3">
            No período: <strong>{{ $totalVideoProcessado }}</strong> vídeo(s) · <strong>{{ $totalImagemProcessada }}</strong> foto(s)
        </div>
    @endif
</div>

{{-- ===== Storage ===== --}}
<h6 class="fw-bold text-uppercase small text-muted mb-2">Armazenamento & assinantes</h6>
<div class="row g-3 mb-4">
    <div class="col-md-8">
        <div class="panda-card h-100">
            <div class="d-flex align-items-start gap-3">
                <div style="font-size:2rem; color:#f59e0b;"><i class="bi bi-hdd-fill"></i></div>
                <div class="flex-grow-1">
                    <div class="small text-muted mb-1">
                        Armazenamento — disco atual: <strong>{{ strtoupper($storageInfo['tipo']) }}</strong>
                    </div>
                    @if(($storageInfo['tipo'] ?? '') === 'local' && isset($storageInfo['total_gb']))
                        {{-- Local: mostra ocupado de disponível --}}
                        <div class="h4 fw-bold mb-2">
                            {{ number_format($storageInfo['usado_gb'], 1, ',', '.') }} GB
                            <span class="text-muted fw-normal fs-6">de {{ number_format($storageInfo['total_gb'], 1, ',', '.') }} GB</span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar {{ $storageInfo['pct_usado'] > 85 ? 'bg-danger' : ($storageInfo['pct_usado'] > 70 ? 'bg-warning' : 'bg-success') }}"
                                 style="width: {{ $storageInfo['pct_usado'] }}%;"></div>
                        </div>
                        <div class="small text-muted mt-1">
                            {{ $storageInfo['pct_usado'] }}% usado ·
                            {{ number_format($storageInfo['livre_gb'], 1, ',', '.') }} GB livres ·
                            <strong>{{ number_format($storageGb, 2, ',', '.') }} GB</strong> em vídeos do app
                        </div>
                    @else
                        {{-- S3 (bucket cloud sem limite conhecido): só mostra usado no bucket --}}
                        <div class="h4 fw-bold mb-0">{{ number_format($storageGb, 2, ',', '.') }} GB</div>
                        <div class="small text-muted">
                            Armazenados em bucket S3 — limite dependente do plano contratado com o provedor.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <x-theme::stat-card label="Assinantes ativos" value="{{ $assinantesAtivos }} / {{ $usuariosTotal }}" icon="bi-people-fill" color="success" />
    </div>
</div>

{{-- ===== Otimização de vídeos ===== --}}
<h6 class="fw-bold text-uppercase small text-muted mb-2">Otimização de vídeos</h6>
<div class="row g-3 mb-4">
    <div class="col-md-5">
        <div class="panda-card h-100">
            <div class="d-flex align-items-start gap-3">
                <div style="font-size:2rem; color:#22c55e;"><i class="bi bi-arrows-angle-contract"></i></div>
                <div class="flex-grow-1">
                    <div class="small text-muted mb-1">Economia de armazenamento</div>
                    <div class="h4 fw-bold mb-1">
                        {{ number_format($otimizacao['gb_economizados'], 2, ',', '.') }} GB
                        @if($otimizacao['percentual'] > 0)
                            <span class="text-success fw-normal fs-6">−{{ number_format($otimizacao['percentual'], 1, ',', '.') }}%</span>
                        @endif
                    </div>
                    <div class="small text-muted">
                        @if($otimizacao['otimizados'] > 0)
                            {{ $otimizacao['otimizados'] }} de {{ $otimizacao['total'] }} vídeos reduzidos ·
                            sem otimizar ocupariam {{ number_format($otimizacao['gb_sem_otimizacao'], 2, ',', '.') }} GB
                        @else
                            Nenhum vídeo reduzido ainda. A conta começa nos próximos envios.
                        @endif
                    </div>
                    @if($otimizacao['otimizados'] > 0)
                        <div class="small text-muted mt-2">
                            <i class="bi bi-laptop me-1"></i>{{ $otimizacao['por_navegador'] }} no navegador
                            <span class="mx-1">·</span>
                            <i class="bi bi-hdd-network me-1"></i>{{ $otimizacao['por_servidor'] }} no servidor
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="panda-card h-100">
            <div class="small text-muted mb-2">
                <i class="bi bi-cpu me-1"></i>Reflexo no processamento — tempo médio real do pipeline
            </div>

            @php
                $grupos = [
                    ['rotulo' => 'Já chegou reduzido (navegador)', 'seg' => $otimizacao['seg_medio_navegador'], 'n' => $otimizacao['amostra_navegador'], 'cor' => 'success'],
                    ['rotulo' => 'Normalizado no servidor', 'seg' => $otimizacao['seg_medio_servidor'], 'n' => $otimizacao['amostra_servidor'], 'cor' => 'warning'],
                    ['rotulo' => 'Sem otimização', 'seg' => $otimizacao['seg_medio_sem_otimizacao'], 'n' => $otimizacao['amostra_sem_otimizacao'], 'cor' => 'secondary'],
                ];
                $comAmostra = array_filter($grupos, fn ($g) => $g['n'] > 0);
            @endphp

            @forelse($comAmostra as $g)
                <div class="d-flex justify-content-between align-items-baseline py-1">
                    <span class="small">
                        <span class="badge bg-{{ $g['cor'] }}-subtle text-{{ $g['cor'] }}-emphasis">{{ $g['n'] }}</span>
                        {{ $g['rotulo'] }}
                    </span>
                    <strong>{{ $g['seg'] }}s</strong>
                </div>
            @empty
                <div class="small text-muted py-2">
                    Ainda sem medições. O tempo passa a ser gravado a cada vídeo processado daqui pra frente.
                </div>
            @endforelse

            <hr class="my-2">
            <div class="d-flex justify-content-between align-items-baseline py-1 small">
                <span>
                    <i class="bi bi-cpu-fill me-1"></i>Capacidade de encode
                    <span class="text-muted">— {{ $capacidade['cores'] }} cores ·
                    {{ $capacidade['workers'] }} worker(s) × {{ $capacidade['threads'] }} threads</span>
                </span>
                <strong class="{{ $capacidade['sobra'] < 0 ? 'text-danger' : ($capacidade['sobra'] > 0 ? 'text-muted' : 'text-success') }}">
                    @if($capacidade['sobra'] < 0)
                        {{ abs($capacidade['sobra']) }} acima da CPU
                    @elseif($capacidade['sobra'] > 0)
                        {{ $capacidade['sobra'] }} core(s) ociosos
                    @else
                        no ponto
                    @endif
                </strong>
            </div>
            @if($capacidade['sobra'] > 0)
                <div class="text-muted" style="font-size:.75rem;">
                    Sobra CPU: subir mais um `queue:work` processa
                    {{ $capacidade['workers'] + 1 }} vídeos em paralelo (ajuste QUEUE_WORKERS junto).
                </div>
            @elseif($capacidade['sobra'] < 0)
                <div class="text-danger" style="font-size:.75rem;">
                    Workers × threads passa dos cores — os encodes disputam CPU e cada um fica mais lento.
                </div>
            @endif

            <hr class="my-2">
            <div class="text-muted" style="font-size:.75rem; line-height:1.5;">
                Em <strong>vídeo</strong>, reduzir pixels economiza banda e disco, não CPU: a saída é
                sempre 1080x1920, e entrada em 4K custa só ~5% a mais que Full HD no encode. Normalizar
                no servidor (quando o navegador não consegue) cobra <strong>um encode a mais</strong> —
                por isso esse grupo aparece separado, e não somado como economia.
                Em <strong>foto</strong> é diferente: a saída mantém o tamanho do original, então um
                arquivo menor entrando significa menos trabalho em cada etapa.
            </div>
        </div>
    </div>
</div>

{{-- ===== Conteúdo publicado (snapshot) ===== --}}
<h6 class="fw-bold text-uppercase small text-muted mb-2">Conteúdo publicado</h6>
<div class="row g-3">
    <div class="col-6 col-md-3">
        <x-theme::stat-card label="Eventos ativos" value="{{ $eventosAtivos }} / {{ $eventosTotal }}" icon="bi-calendar-event" color="info" />
    </div>
    <div class="col-6 col-md-3">
        <x-theme::stat-card label="Álbuns publicados" value="{{ $albunsPublicados }} / {{ $albunsTotal }}" icon="bi-images" color="primary" />
    </div>
</div>

@push('scripts')
    <script>
        // Chart.js: instância única do gráfico de receita. Setup roda depois
        // que o script CDN carrega (defer). Se o script falhar, o canvas fica
        // em branco mas a página não quebra.
        document.addEventListener('DOMContentLoaded', () => {
            const wait = () => {
                if (typeof Chart === 'undefined') return setTimeout(wait, 50);
                const el = document.getElementById('chart-receita');
                if (!el) return;
                const labels = JSON.parse(el.dataset.labels || '[]');
                const vendas = JSON.parse(el.dataset.vendas || '[]');
                const assinaturas = JSON.parse(el.dataset.assinaturas || '[]');
                new Chart(el, {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [
                            {
                                label: 'Vendas de vídeos',
                                data: vendas,
                                backgroundColor: '#3b82f6',
                                borderRadius: 4,
                                stack: 'total',
                            },
                            {
                                label: 'Assinaturas',
                                data: assinaturas,
                                backgroundColor: '#8b5cf6',
                                borderRadius: 4,
                                stack: 'total',
                            },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { position: 'top', align: 'end' },
                            tooltip: {
                                callbacks: {
                                    label: (ctx) => `${ctx.dataset.label}: R$ ${ctx.parsed.y.toFixed(2).replace('.', ',')}`,
                                },
                            },
                        },
                        scales: {
                            x: { stacked: true, grid: { display: false } },
                            y: {
                                stacked: true,
                                beginAtZero: true,
                                ticks: {
                                    callback: (v) => 'R$ ' + Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 0 }),
                                },
                            },
                        },
                    },
                });
            };
            wait();
        });
    </script>
@endpush
@endsection
