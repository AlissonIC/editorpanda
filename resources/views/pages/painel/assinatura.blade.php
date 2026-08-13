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

                {{-- Sem botão de cancelar: o plano é pago por período fechado e
                     não renova sozinho. Quem não quer continuar simplesmente não
                     renova — não há assinatura recorrente pra interromper. --}}
                <div class="d-flex gap-2 flex-wrap">
                    @if($assinaturaAtual->plano_id)
                        {{-- Renovar é o mesmo checkout de assinar: o backend
                             reconhece que é o plano vigente e trata como renovação. --}}
                        <button type="button" class="btn btn-dark-panda js-checkout-plano"
                                data-plano="{{ $assinaturaAtual->plano_id }}">
                            <i class="bi bi-arrow-clockwise me-1"></i>
                            Renovar por 30 dias (R$ {{ number_format((float) $assinaturaAtual->preco_pago, 2, ',', '.') }})
                        </button>
                    @endif
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
                                        class="btn {{ $atual ? 'btn-outline-dark' : 'btn-dark' }} w-100 rounded-pill py-2 js-checkout-plano"
                                        data-plano="{{ $p->id }}">
                                    {{ $atual ? 'Renovar este plano' : ($assinaturaAtual ? 'Trocar para este' : 'Assinar') }}
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
                <li>Cada assinatura dura 30 dias e não renova sozinha — nada é
                    cobrado sem você mandar.</li>
                <li>Renove antes do vencimento para não perder acesso.</li>
                <li>Trocar de plano cancela o atual e inicia o novo do zero.</li>
                <li>Se não renovar, o acesso segue normal até a data de vencimento.</li>
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
                            <td class="fw-semibold">
                                {{ $a->plano_nome }}
                                <div class="small text-muted">{{ $a->tipoLabel() }}</div>
                            </td>
                            {{-- Datas podem faltar em registro antigo — nunca vale
                                 derrubar a tela por causa do histórico. --}}
                            <td>{{ $a->iniciado_em?->format('d/m/Y') ?? '—' }}</td>
                            <td>{{ $a->expira_em?->format('d/m/Y') ?? '—' }}</td>
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
{{-- ===== Checkout do plano ===== --}}
<div class="modal fade" id="modal-checkout" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content ck-modal">
            <div class="modal-header border-0 pb-0">
                <div>
                    <span class="badge rounded-pill" id="ck-tipo-badge">Assinatura</span>
                    <h5 class="modal-title fw-bold mt-2 mb-0" id="ck-titulo">Confirmar plano</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body pt-2">
                {{-- Carregando o resumo --}}
                <div id="ck-carregando" class="text-center py-5">
                    <div class="spinner-border text-secondary" role="status"></div>
                    <p class="small text-muted mt-2 mb-0">Montando seu pedido…</p>
                </div>

                <div id="ck-conteudo" class="d-none">
                    <div class="row g-4">
                        {{-- Coluna esquerda: o que está sendo comprado --}}
                        <div class="col-lg-5">
                            <div class="ck-resumo">
                                <div class="small text-muted text-uppercase">Plano escolhido</div>
                                <div class="h5 fw-bold mb-1" id="ck-plano-nome"></div>
                                <p class="small text-muted mb-3" id="ck-plano-desc"></p>

                                <ul class="list-unstyled small mb-3" id="ck-plano-itens"></ul>

                                {{-- Só aparece em troca de plano: antes → depois --}}
                                <div id="ck-comparacao" class="d-none">
                                    <div class="small text-muted text-uppercase mb-1">O que muda</div>
                                    <table class="table table-sm small mb-3" id="ck-comparacao-tabela"></table>
                                </div>

                                <div class="ck-vigencia small">
                                    <i class="bi bi-calendar-check me-1"></i>
                                    <span id="ck-vigencia-texto"></span>
                                </div>

                                <div id="ck-avisos" class="mt-3"></div>
                            </div>
                        </div>

                        {{-- Coluna direita: pagamento --}}
                        <div class="col-lg-7">
                            <div class="ck-total d-flex justify-content-between align-items-baseline mb-3">
                                <span class="text-muted">Total a pagar</span>
                                <span class="h4 fw-bold mb-0" id="ck-total"></span>
                            </div>

                            {{-- Etapa 1: dados de cobrança.
                                 Um campo só. Nome e e-mail vêm do cadastro e o
                                 Mercado Pago não exige mais nada — endereço é
                                 opcional pra ele, e campo a mais aqui é só
                                 atrito. --}}
                            <div id="ck-etapa-dados">
                                <label class="form-label small mb-1">CPF do pagador <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="ck-cpf" inputmode="numeric"
                                       autocomplete="off" maxlength="14" placeholder="000.000.000-00">
                                <div class="invalid-feedback" id="ck-cpf-erro"></div>
                                <div class="form-text small" id="ck-cobranca-em"></div>

                                <button type="button" class="btn btn-dark-panda w-100 mt-3" id="ck-continuar">
                                    Continuar para o pagamento
                                </button>
                            </div>

                            {{-- Etapa 2: forma de pagamento --}}
                            <div id="ck-etapa-pagamento" class="d-none">
                                <div class="ck-metodos mb-3">
                                    <button type="button" class="ck-metodo is-active" data-metodo="pix">
                                        <i class="bi bi-qr-code"></i>
                                        <span>PIX</span>
                                        <small>Aprovação na hora</small>
                                    </button>
                                    <button type="button" class="ck-metodo" data-metodo="cartao">
                                        <i class="bi bi-credit-card"></i>
                                        <span>Cartão</span>
                                        <small>Até 12x</small>
                                    </button>
                                </div>

                                {{-- PIX --}}
                                <div id="ck-pix">
                                    <div class="text-center" id="ck-pix-carregando">
                                        <div class="spinner-border spinner-border-sm text-secondary"></div>
                                        <p class="small text-muted mt-2">Gerando o código PIX…</p>
                                    </div>
                                    <div class="d-none" id="ck-pix-pronto">
                                        <div class="text-center">
                                            <img id="ck-pix-qr" alt="QR Code PIX" class="ck-qr">
                                        </div>
                                        <p class="small text-muted text-center mt-2 mb-2">
                                            Abra o app do banco, escaneie o código e a liberação é automática.
                                        </p>
                                        <div class="input-group input-group-sm">
                                            <input type="text" class="form-control" id="ck-pix-codigo" readonly>
                                            <button class="btn btn-outline-secondary" type="button" id="ck-pix-copiar">
                                                <i class="bi bi-clipboard me-1"></i>Copiar
                                            </button>
                                        </div>
                                        <div class="small text-muted mt-2 text-center" id="ck-pix-expira"></div>
                                    </div>
                                </div>

                                {{-- Cartão (Bricks do Mercado Pago) --}}
                                <div id="ck-cartao" class="d-none">
                                    <div id="ck-cartao-brick"></div>
                                </div>

                                <div class="alert alert-danger py-2 small mt-3 d-none" id="ck-erro"></div>

                                <div class="d-flex align-items-center justify-content-center gap-2 small text-muted mt-3">
                                    <i class="bi bi-shield-lock"></i>
                                    Pagamento processado pelo Mercado Pago.
                                </div>
                            </div>

                            {{-- Etapa 3: aprovado --}}
                            <div id="ck-etapa-ok" class="d-none text-center py-4">
                                <i class="bi bi-check-circle-fill text-success" style="font-size:3rem;"></i>
                                <h5 class="fw-bold mt-3 mb-1">Pagamento aprovado!</h5>
                                <p class="text-muted small mb-0" id="ck-ok-texto"></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/painel/assinatura.js')
@endpush
