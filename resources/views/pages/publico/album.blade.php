@extends('theme::layouts.publico-produto')

@section('titulo', $album->nome . ' — ' . $album->evento->nome)

@section('conteudo')
<section class="pv-hero">
    <div class="container py-4">
        <div class="mb-2 small">
            <a href="{{ route('publico.evento.show', $album->evento->slug) }}" class="text-muted text-decoration-none">
                <i class="bi bi-arrow-left me-1"></i> {{ $album->evento->nome }}
            </a>
        </div>
        <h1 class="fw-bold mb-1">{{ $album->nome }}</h1>
        @if($album->subtitulo)
            <p class="text-muted mb-0">{{ $album->subtitulo }}</p>
        @endif
    </div>
</section>

@php $gratis = $preco <= 0; @endphp
<section class="container py-4">
    <div
        id="album-app"
        class="row g-4"
        data-checkout-url="{{ route('publico.checkout.store', $album->slug) }}"
        data-preco="{{ $preco }}"
        data-gratis="{{ $gratis ? '1' : '0' }}"
    >
        <div class="col-lg-8">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="fw-bold mb-0">Vídeos</h4>
                <div class="small text-muted">
                    {{ count($videos) }} vídeos ·
                    @if($gratis)
                        <span class="text-success fw-semibold">Grátis</span>
                    @else
                        R$ {{ number_format($preco, 2, ',', '.') }} cada
                    @endif
                </div>
            </div>

            @if(count($videos) === 0)
                <div class="pv-empty">
                    <i class="bi bi-film"></i>
                    <p>Nenhum vídeo neste álbum ainda.</p>
                </div>
            @else
                <div class="pv-video-grid">
                    @foreach($videos as $v)
                        <label class="pv-video-card {{ $v['processado'] ? '' : 'is-processing' }}">
                            <input type="checkbox" class="pv-video-check" value="{{ $v['id'] }}">
                            <div class="pv-video-thumb">
                                @if($v['thumbnail_url'])
                                    <img src="{{ $v['thumbnail_url'] }}" alt="" loading="lazy">
                                @else
                                    <i class="bi bi-film"></i>
                                @endif
                                <div class="pv-check-badge"><i class="bi bi-check-lg"></i></div>
                                @if(!$v['processado'])
                                    <div class="pv-processing-tag">Em processamento</div>
                                @endif
                            </div>
                            <div class="pv-video-info">
                                <div class="text-truncate small fw-medium">{{ $v['nome'] }}</div>
                                <div class="small text-muted">{{ $v['duracao'] }}</div>
                            </div>
                        </label>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="col-lg-4">
            <div class="pv-checkout-card">
                <h5 class="fw-bold mb-3">{{ $gratis ? 'Baixar vídeos' : 'Checkout' }}</h5>
                <div class="pv-summary mb-3">
                    <div class="d-flex justify-content-between">
                        <span>Vídeos selecionados</span>
                        <strong id="pv-sel-count">0</strong>
                    </div>
                    @if(! $gratis)
                        <div class="d-flex justify-content-between text-muted small">
                            <span>Preço unitário</span>
                            <span>R$ {{ number_format($preco, 2, ',', '.') }}</span>
                        </div>
                    @endif
                    <hr>
                    <div class="d-flex justify-content-between fs-5 fw-bold">
                        <span>Total</span>
                        @if($gratis)
                            <span class="text-success">Grátis</span>
                        @else
                            <span>R$ <span id="pv-total">0,00</span></span>
                        @endif
                    </div>
                </div>

                <form id="pv-checkout-form" novalidate>
                    @csrf
                    <div class="mb-2">
                        <label class="form-label small">Seu nome</label>
                        <input type="text" name="nome" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">E-mail</label>
                        <input type="email" name="email" class="form-control" required>
                        <small class="text-muted">
                            {{ $gratis ? 'Enviaremos os vídeos para este e-mail.' : 'Os vídeos serão enviados para este e-mail.' }}
                        </small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">WhatsApp (opcional)</label>
                        <input type="text" name="whatsapp" class="form-control" placeholder="(11) 99999-9999">
                    </div>
                    <button type="submit" class="btn btn-dark w-100 py-2 fw-semibold" id="pv-checkout-btn" disabled>
                        {{ $gratis ? 'Baixar grátis' : 'Finalizar compra' }}
                    </button>
                    <div class="text-center small text-muted mt-2">
                        <a href="{{ route('publico.acesso') }}">Já comprei — acessar meus vídeos</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
@endsection

@push('scripts')
    @vite('resources/js/pages/publico/album.js')
@endpush
