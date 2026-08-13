@extends('theme::layouts.painel')

@section('titulo', 'Pedido #' . $pedido->id)

@php
    $badge = match ($pedido->status) {
        'pago' => 'success',
        'cancelado' => 'danger',
        default => 'warning',
    };
    $brl = fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
    // Erro só vale destaque enquanto o pedido não entrou. Depois de pago, uma
    // tentativa recusada no meio do caminho é história, não problema.
    $temFalha = $pedido->status !== 'pago'
        && ($gatewayMensagem || $gatewayCausas || in_array($pedido->gateway_status, ['rejected', 'cancelled'], true));
@endphp

@section('conteudo')
<x-theme::page-header
    titulo="Pedido #{{ $pedido->id }}"
    subtitulo="{{ $pedido->created_at?->format('d/m/Y \à\s H:i') }}"
>
    <a href="{{ route('painel.pedidos.index') }}" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</x-theme::page-header>

<div class="row g-4">
    <div class="col-lg-8">
        {{-- Resumo --}}
        <div class="panda-card mb-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
                <div>
                    <div class="small text-muted text-uppercase">Total da compra</div>
                    <div class="h3 fw-bold mb-0">{{ $brl($pedido->total) }}</div>
                </div>
                <span class="badge bg-{{ $badge }}-subtle text-{{ $badge }}-emphasis fs-6 align-self-center">
                    {{ ucfirst($pedido->status) }}
                </span>
            </div>

            <div class="row g-3">
                <div class="col-sm-6 col-lg-3">
                    <div class="small text-muted">Meio de pagamento</div>
                    <div class="fw-semibold">{{ $metodoLabel }}</div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="small text-muted">Pago em</div>
                    <div class="fw-semibold">{{ $pedido->pago_em?->format('d/m/Y H:i') ?? '—' }}</div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="small text-muted">Álbum</div>
                    <div class="fw-semibold">{{ $pedido->album?->nome ?? '—' }}</div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="small text-muted">Evento</div>
                    <div class="fw-semibold">{{ $pedido->album?->evento?->nome ?? '—' }}</div>
                </div>
            </div>

            @if($pedido->desconto_cupom > 0 || $pedido->desconto_quantidade > 0)
                <hr class="my-3">
                <div class="row g-3 small">
                    @if($pedido->desconto_cupom > 0)
                        <div class="col-sm-6">
                            <span class="text-muted">Desconto por cupom</span>
                            @if($pedido->cupom)<span class="badge bg-light text-dark">{{ $pedido->cupom->codigo }}</span>@endif
                            <div class="fw-semibold text-success">− {{ $brl($pedido->desconto_cupom) }}</div>
                        </div>
                    @endif
                    @if($pedido->desconto_quantidade > 0)
                        <div class="col-sm-6">
                            <span class="text-muted">Desconto por quantidade</span>
                            <div class="fw-semibold text-success">− {{ $brl($pedido->desconto_quantidade) }}</div>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        {{-- O que o gateway respondeu --}}
        <div class="panda-card mb-4">
            <h6 class="fw-bold mb-3">
                <i class="bi bi-credit-card-2-back me-1"></i>Pagamento no Mercado Pago
            </h6>

            @if(! $pedido->gateway_id)
                <p class="small text-muted mb-0">
                    Nenhuma cobrança chegou a ser criada no gateway
                    @if($pedido->total <= 0) — este pedido é gratuito.@else para este pedido.@endif
                </p>
            @else
                <div class="row g-3 mb-3">
                    <div class="col-sm-4">
                        <div class="small text-muted">Situação no gateway</div>
                        <div class="fw-semibold">{{ $gatewayStatusLabel ?? '—' }}</div>
                    </div>
                    <div class="col-sm-4">
                        <div class="small text-muted">ID do pagamento</div>
                        <div class="fw-semibold font-monospace small">{{ $pedido->gateway_id }}</div>
                    </div>
                    <div class="col-sm-4">
                        <div class="small text-muted">PIX expira em</div>
                        <div class="fw-semibold">{{ $pedido->pix_expires_at?->format('d/m/Y H:i') ?? '—' }}</div>
                    </div>
                </div>

                @if($gatewayDetalhe)
                    <div class="alert {{ $temFalha ? 'alert-danger' : 'alert-light' }} py-2 small mb-2">
                        <strong>Retorno do gateway:</strong> {{ $gatewayDetalhe }}
                    </div>
                @endif

                {{-- Erro da REQUISIÇÃO (payload inválido, conta sem permissão).
                     Diferente de recusa de pagamento e quase sempre é o que
                     trava uma integração — por isso vem separado e em destaque. --}}
                @if($gatewayMensagem || $gatewayCausas)
                    <div class="alert alert-danger py-2 small mb-0">
                        <div class="fw-semibold mb-1">
                            <i class="bi bi-exclamation-octagon me-1"></i>O Mercado Pago recusou a requisição
                        </div>
                        @if($gatewayMensagem)<div>{{ $gatewayMensagem }}</div>@endif
                        @if($gatewayCausas)
                            <ul class="mb-0 ps-3 mt-1">
                                @foreach($gatewayCausas as $c)<li>{{ $c }}</li>@endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            @endif
        </div>

        {{-- Itens --}}
        <div class="panda-card mb-4">
            <h6 class="fw-bold mb-3">Itens da compra ({{ count($itens) }})</h6>
            @if(empty($itens))
                <p class="small text-muted mb-0">Nenhum item registrado neste pedido.</p>
            @else
                <div class="pd-galeria">
                    @foreach($itens as $item)
                        <figure class="pd-item mb-0">
                            @if($item['disponivel'])
                                <button type="button" class="pd-thumb js-abrir-item"
                                        data-nome="{{ $item['nome'] }}"
                                        data-arquivo="{{ $item['arquivo'] }}"
                                        data-foto="{{ $item['eh_foto'] ? '1' : '0' }}"
                                        title="Abrir {{ $item['nome'] }}">
                                    @if($item['thumb'])
                                        <img src="{{ $item['thumb'] }}" alt="{{ $item['nome'] }}" loading="lazy">
                                    @else
                                        <span class="pd-sem-thumb"><i class="bi bi-file-earmark"></i></span>
                                    @endif
                                    <span class="pd-lupa"><i class="bi bi-arrows-fullscreen"></i></span>
                                    @if(! $item['eh_foto'] && $item['duracao'] > 0)
                                        <span class="pd-duracao">
                                            {{ gmdate($item['duracao'] >= 3600 ? 'H:i:s' : 'i:s', $item['duracao']) }}
                                        </span>
                                    @endif
                                </button>
                            @else
                                <span class="pd-thumb pd-thumb-vazio" title="Arquivo não está mais disponível">
                                    <i class="bi bi-file-earmark-x"></i>
                                </span>
                            @endif

                            <figcaption class="pd-legenda">
                                <span class="text-truncate d-block">{{ $item['nome'] }}</span>
                                <span class="text-muted">{{ $brl($item['preco']) }}</span>
                                @if($item['filtro'])
                                    <span class="badge bg-light text-dark">filtro: {{ $item['filtro'] }}</span>
                                @endif
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Linha do tempo --}}
        <div class="panda-card">
            <h6 class="fw-bold mb-3">Histórico do pagamento</h6>
            @if($pedido->pagamentoLogs->isEmpty())
                <p class="small text-muted mb-0">Nada registrado ainda.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm small align-middle mb-0">
                        <tbody>
                            @foreach($pedido->pagamentoLogs as $log)
                                @php
                                    $cor = match ($log->nivel) {
                                        'error', 'critical' => 'danger',
                                        'warning' => 'warning',
                                        default => 'secondary',
                                    };
                                @endphp
                                <tr>
                                    <td class="text-muted text-nowrap" style="width:130px;">
                                        {{ $log->created_at?->format('d/m/Y H:i') }}
                                    </td>
                                    <td style="width:150px;">
                                        <span class="badge bg-{{ $cor }}-subtle text-{{ $cor }}-emphasis">{{ $log->evento }}</span>
                                    </td>
                                    <td>{{ $log->mensagem }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="col-lg-4">
        {{-- Comprador --}}
        <div class="panda-card mb-3">
            <h6 class="fw-bold mb-3">Comprador</h6>
            <div class="small">
                <div class="text-muted">Nome</div>
                <div class="fw-semibold mb-2">{{ $pedido->comprador_nome ?? '—' }}</div>
                <div class="text-muted">E-mail</div>
                <div class="fw-semibold mb-2 text-break">{{ $pedido->comprador_email ?? '—' }}</div>
                <div class="text-muted">WhatsApp</div>
                <div class="fw-semibold">{{ $pedido->comprador_whatsapp ?? '—' }}</div>
            </div>
        </div>

        @if(auth()->user()->isAdmin() && $pedido->user)
            <div class="panda-card mb-3">
                <h6 class="fw-bold mb-2">Dono do álbum</h6>
                <div class="small">
                    <div class="fw-semibold">{{ $pedido->user->nome }}</div>
                    <div class="text-muted text-break">{{ $pedido->user->email }}</div>
                </div>
            </div>
        @endif

        {{-- Troca manual de status --}}
        <div class="panda-card" id="pedido-status"
             data-url="{{ route('painel.pedidos.status', $pedido) }}"
             data-status-atual="{{ $pedido->status }}">
            <h6 class="fw-bold mb-2">Alterar status</h6>

            @if(empty($statusPermitidos))
                {{-- Nada some sem explicação: um card vazio faz o usuário achar
                     que a tela quebrou. --}}
                <p class="small text-muted mb-0">
                    @if($ehAdmin)
                        Não há mudança de status disponível para este pedido.
                    @else
                        Este pedido já foi {{ $pedido->status }} e só o administrador pode alterá-lo
                        a partir daqui — mexer nele agora move dinheiro e o acesso do comprador.
                    @endif
                </p>
            @else
                <p class="small text-muted">
                    @if($ehAdmin)
                        Use quando o pagamento aconteceu fora do sistema ou o gateway não confirmou.
                        Confirmar libera os arquivos ao comprador e credita o vendedor.
                    @else
                        Este pedido não foi pago. Você pode cancelá-lo para tirá-lo da sua lista.
                    @endif
                </p>

                @if($pedido->status === 'pago')
                    <div class="alert alert-warning py-2 small">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Tirar este pedido de "pago" <strong>estorna o valor</strong> do saldo do vendedor.
                        Se ele já sacou, o saldo fica negativo até a próxima venda cobrir.
                    </div>
                @endif

                <label class="form-label small mb-1">Motivo <span class="text-danger">*</span></label>
                <textarea class="form-control form-control-sm mb-2" id="pedido-motivo" rows="2"
                          maxlength="300" placeholder="Ex.: comprovante de PIX enviado por e-mail"></textarea>
                <div class="invalid-feedback d-block small d-none" id="pedido-motivo-erro"></div>

                <div class="d-grid gap-2">
                    @foreach($statusPermitidos as $valor => $rotulo)
                        <button type="button"
                                class="btn btn-sm {{ $valor === 'pago' ? 'btn-dark-panda' : 'btn-outline-secondary' }} js-trocar-status"
                                data-status="{{ $valor }}">
                            {{ $rotulo }}
                        </button>
                    @endforeach
                </div>

                @unless($ehAdmin)
                    <p class="small text-muted mt-2 mb-0">
                        Confirmar pagamento é uma ação do administrador.
                    </p>
                @endunless
            @endif
        </div>
    </div>
</div>
{{-- Visualizador em tela cheia dos itens comprados --}}
<div class="modal fade" id="modal-item" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content pd-visor">
            <div class="modal-header border-0 py-2">
                <h6 class="modal-title text-truncate mb-0" id="pd-visor-nome"></h6>
                <div class="d-flex gap-2 align-items-center">
                    <button type="button" class="btn btn-sm btn-outline-light" id="pd-tela-cheia"
                            title="Tela cheia">
                        <i class="bi bi-arrows-fullscreen"></i>
                    </button>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="modal-body p-0" id="pd-visor-palco"></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/painel/pedido-detalhe.js')
@endpush
