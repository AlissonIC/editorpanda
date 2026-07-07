@extends('theme::layouts.painel')

@section('titulo', 'Minha assinatura')

@section('conteudo')
<x-theme::page-header
    titulo="Minha assinatura"
    subtitulo="Plano ativo, renovação e histórico"
/>

@php
    $usadoBytes = (int) auth()->user()->armazenamento_bytes;
    $limiteBytes = auth()->user()->armazenamentoLimiteBytes();
    $percentual = auth()->user()->armazenamentoPercentual();
    $fmt = function ($b) {
        if (! $b) return '0 B';
        if ($b < 1024) return "{$b} B";
        if ($b < 1048576) return number_format($b / 1024, 1, ',', '.') . ' KB';
        if ($b < 1073741824) return number_format($b / 1048576, 1, ',', '.') . ' MB';
        return number_format($b / 1073741824, 2, ',', '.') . ' GB';
    };
@endphp

<div class="row g-4">
    <div class="col-lg-8">
        {{-- Plano atual --}}
        <div class="panda-card mb-4">
            @if($assinaturaAtual)
                @php
                    $diasRestantes = max(0, (int) ceil(now()->diffInDays($assinaturaAtual->expira_em, false)));
                    $expiraProx = $diasRestantes <= 7;
                @endphp
                <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
                    <div>
                        <div class="text-uppercase small text-muted">Plano atual</div>
                        <h3 class="h4 fw-bold mb-0">{{ $assinaturaAtual->plano_nome }}</h3>
                    </div>
                    <span class="badge bg-success-subtle text-success-emphasis fs-6">Ativa</span>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-sm-4">
                        <div class="text-muted small">Início</div>
                        <div class="fw-semibold">{{ $assinaturaAtual->iniciado_em->format('d/m/Y') }}</div>
                    </div>
                    <div class="col-sm-4">
                        <div class="text-muted small">Vence em</div>
                        <div class="fw-semibold {{ $expiraProx ? 'text-warning' : '' }}">
                            {{ $assinaturaAtual->expira_em->format('d/m/Y') }}
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="text-muted small">Dias restantes</div>
                        <div class="fw-semibold {{ $expiraProx ? 'text-warning' : '' }}">{{ $diasRestantes }} dia(s)</div>
                    </div>
                </div>

                {{-- Barra de progresso do tempo --}}
                @php
                    $totalDias = max(1, $assinaturaAtual->duracao_dias ?: 30);
                    $percentualTempo = min(100, max(0, ($totalDias - $diasRestantes) / $totalDias * 100));
                @endphp
                <div class="progress mb-3" style="height: 8px;">
                    <div class="progress-bar {{ $expiraProx ? 'bg-warning' : 'bg-success' }}"
                         style="width: {{ $percentualTempo }}%"></div>
                </div>

                @if($expiraProx)
                    <div class="alert alert-warning py-2 small mb-3">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Sua assinatura vence em {{ $diasRestantes }} dia(s). Renove para não perder acesso.
                    </div>
                @endif

                <div class="d-flex gap-2 flex-wrap" id="acoes-assinatura">
                    <button type="button" class="btn btn-dark-panda" data-action="renovar">
                        <i class="bi bi-arrow-clockwise me-1"></i>
                        Renovar por 30 dias (R$ {{ number_format((float) $assinaturaAtual->preco_pago, 2, ',', '.') }})
                    </button>
                    <button type="button" class="btn btn-outline-danger" data-action="cancelar">
                        <i class="bi bi-x-circle me-1"></i> Cancelar
                    </button>
                </div>
            @else
                <div class="text-center py-4">
                    <i class="bi bi-emoji-frown" style="font-size: 2.5rem; color:#9ca3af;"></i>
                    <h3 class="h5 fw-bold mt-3 mb-1">Você ainda não tem um plano ativo</h3>
                    <p class="text-muted">Escolha um dos planos abaixo pra começar a vender seus vídeos.</p>
                </div>
            @endif
        </div>

        {{-- Planos disponíveis --}}
        <div class="panda-card">
            <h3 class="h6 fw-bold mb-3">
                {{ $assinaturaAtual ? 'Trocar de plano' : 'Planos disponíveis' }}
            </h3>
            @if($planosDisponiveis->isEmpty())
                <p class="text-muted small mb-0">Nenhum plano disponível no momento.</p>
            @else
                <div class="row g-3" id="planos-lista">
                    @foreach($planosDisponiveis as $p)
                        @php $atual = $assinaturaAtual && $assinaturaAtual->plano_id === $p->id; @endphp
                        <div class="col-md-6 col-lg-4">
                            <div class="plan-card {{ $p->popular ? 'plan-popular' : '' }} {{ $atual ? 'is-atual' : '' }}">
                                @if($p->popular)
                                    <span class="plan-badge">Popular</span>
                                @endif
                                @if($atual)
                                    <span class="plan-badge" style="left:auto; right:1.5rem; background:#28c76f;">Seu plano</span>
                                @endif
                                <h4 class="fw-bold">{{ $p->nome }}</h4>
                                @if($p->descricao)
                                    <p class="text-muted small mb-2">{{ $p->descricao }}</p>
                                @endif
                                <div class="plan-price">
                                    R$ {{ number_format((float) $p->preco, 2, ',', '.') }}
                                    <span>/mês</span>
                                </div>
                                <ul class="plan-features list-unstyled small mb-3">
                                    <li><i class="bi bi-check2"></i> {{ $p->armazenamento_gb }} GB de armazenamento</li>
                                    <li><i class="bi bi-check2"></i> {{ number_format((float) $p->taxa_por_venda, 2, ',', '.') }}% de taxa por venda</li>
                                </ul>
                                <button type="button"
                                        class="btn {{ $atual ? 'btn-outline-secondary' : 'btn-dark' }} w-100 rounded-pill py-2"
                                        data-assinar="{{ $p->id }}"
                                        data-nome="{{ $p->nome }}"
                                        {{ $atual ? 'disabled' : '' }}>
                                    {{ $atual ? 'Plano atual' : ($assinaturaAtual ? 'Trocar para este' : 'Assinar') }}
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="col-lg-4">
        {{-- Armazenamento --}}
        <div class="panda-card mb-3">
            <div class="d-flex justify-content-between align-items-baseline mb-2">
                <h6 class="fw-bold mb-0">Armazenamento</h6>
                @if(auth()->user()->plano)
                    <small class="text-muted">Plano {{ auth()->user()->plano->nome }}</small>
                @endif
            </div>
            <div class="fs-5 fw-bold">{{ $fmt($usadoBytes) }}
                @if($limiteBytes)
                    <span class="text-muted small fw-normal">de {{ $fmt((int) $limiteBytes) }}</span>
                @endif
            </div>
            <div class="progress mt-2" style="height: 8px;">
                <div class="progress-bar {{ $percentual >= 95 ? 'bg-danger' : ($percentual >= 80 ? 'bg-warning' : 'bg-success') }}"
                     style="width: {{ $limiteBytes ? min(100, $percentual) : 0 }}%"></div>
            </div>
        </div>

        {{-- Info --}}
        <div class="panda-card small">
            <h6 class="fw-bold mb-2">Como funciona</h6>
            <ul class="text-muted ps-3 mb-0">
                <li>Cada assinatura dura 30 dias.</li>
                <li>Renove antes do vencimento para não perder acesso.</li>
                <li>Trocar de plano cancela o atual e inicia o novo do zero.</li>
                <li>Cancelar mantém acesso até a data de vencimento.</li>
            </ul>
        </div>
    </div>
</div>

{{-- Histórico --}}
<div class="panda-card mt-4">
    <h3 class="h6 fw-bold mb-3">Histórico de assinaturas</h3>
    @if($historico->isEmpty())
        <p class="text-muted small mb-0">Nenhuma assinatura registrada ainda.</p>
    @else
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Plano</th>
                        <th>Início</th>
                        <th>Vencimento</th>
                        <th class="text-end">Valor</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($historico as $a)
                        <tr>
                            <td class="fw-semibold">{{ $a->plano_nome }}</td>
                            <td>{{ $a->iniciado_em->format('d/m/Y') }}</td>
                            <td>{{ $a->expira_em->format('d/m/Y') }}</td>
                            <td class="text-end">R$ {{ number_format((float) $a->preco_pago, 2, ',', '.') }}</td>
                            <td class="text-center">
                                @php
                                    $badge = match($a->status) {
                                        'ativa' => 'success',
                                        'expirada' => 'secondary',
                                        'cancelada' => 'danger',
                                        default => 'secondary',
                                    };
                                @endphp
                                <span class="badge bg-{{ $badge }}-subtle text-{{ $badge }}-emphasis">
                                    {{ ucfirst($a->status) }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/painel/assinatura.js')
@endpush
