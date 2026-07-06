@extends('theme::layouts.publico-produto')

@section('titulo', 'Minhas compras — ' . config('app.name'))

@section('conteudo')
<section class="container py-5">
    <div class="d-flex justify-content-between align-items-baseline mb-4">
        <h2 class="fw-bold mb-0">Minhas compras</h2>
        <span class="text-muted small">{{ auth('comprador')->user()->email }}</span>
    </div>

    @if($pedidos->isEmpty())
        <div class="pv-empty">
            <i class="bi bi-bag"></i>
            <p>Você ainda não tem compras.</p>
        </div>
    @endif

    @foreach($pedidos as $pedido)
        <div class="pv-checkout-card mb-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <div class="fw-bold">{{ $pedido->album->nome }}</div>
                    <div class="small text-muted">
                        Pedido #{{ $pedido->id }} · {{ $pedido->created_at->format('d/m/Y H:i') }}
                        · <span class="badge bg-success-subtle text-success-emphasis">{{ ucfirst($pedido->status) }}</span>
                    </div>
                </div>
                <div class="fw-bold">R$ {{ number_format((float) $pedido->total, 2, ',', '.') }}</div>
            </div>

            <div class="row g-3">
                @foreach($pedido->itens as $item)
                    @php $v = $item->video; @endphp
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="pv-video-card">
                            <div class="pv-video-thumb">
                                @if($v->thumbnail_path)
                                    <img src="{{ route('publico.video.thumb', $v->id) }}" alt="">
                                @else
                                    <i class="bi bi-film"></i>
                                @endif
                                @if($v->status !== 'concluido')
                                    <div class="pv-processing-tag">Em processamento</div>
                                @endif
                            </div>
                            <div class="pv-video-info">
                                <div class="text-truncate small fw-medium">{{ $v->nome }}</div>
                                @if($v->status === 'concluido')
                                    <a href="{{ route('publico.videos.baixar', $v->id) }}" class="btn btn-sm btn-dark w-100 mt-2">
                                        <i class="bi bi-download me-1"></i>Baixar
                                    </a>
                                @else
                                    <div class="small text-muted mt-2">Aguarde…</div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</section>
@endsection
