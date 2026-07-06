@extends('theme::layouts.publico-produto')

@section('titulo', 'Compra confirmada — ' . config('app.name'))

@section('conteudo')
<section class="container py-5">
    <div class="pv-checkout-card text-center mx-auto" style="max-width: 640px;">
        <div class="pv-success-icon mb-3">
            <i class="bi bi-check-circle-fill"></i>
        </div>
        <h2 class="fw-bold">Compra confirmada!</h2>
        <p class="text-muted">
            Pedido #{{ $pedido->id }} · Total R$ {{ number_format((float) $pedido->total, 2, ',', '.') }}
        </p>
        <p class="mb-4">
            Enviamos um e-mail para <strong>{{ $pedido->comprador_email }}</strong> com um link de acesso
            à sua área de compras. Assim você pode voltar quando quiser para baixar seus vídeos.
        </p>
    </div>

    <div class="mt-5">
        <h5 class="fw-bold mb-3">Seus vídeos</h5>
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
                            @auth('comprador')
                                @if($v->status === 'concluido')
                                    <a href="{{ route('publico.videos.baixar', $v->id) }}" class="btn btn-sm btn-dark w-100 mt-2">
                                        <i class="bi bi-download me-1"></i>Baixar
                                    </a>
                                @else
                                    <div class="small text-muted mt-2">Aguardando processamento</div>
                                @endif
                            @endauth
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>
@endsection
