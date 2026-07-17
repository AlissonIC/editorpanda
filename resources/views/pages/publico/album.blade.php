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
                <div class="pv-video-grid" id="pv-video-grid" data-videos="{{ json_encode($videos) }}">
                    @foreach($videos as $i => $v)
                        <div class="pv-video-card" data-video-index="{{ $i }}">
                            <label class="pv-video-check-wrap">
                                <input type="checkbox" class="pv-video-check" value="{{ $v['id'] }}">
                                <div class="pv-check-badge"><i class="bi bi-check-lg"></i></div>
                            </label>
                            <button type="button" class="pv-video-play-btn" data-video-index="{{ $i }}"
                                    title="Pré-visualizar">
                                <div class="pv-video-thumb">
                                    @if($v['thumbnail_url'])
                                        <img src="{{ $v['thumbnail_url'] }}" alt="" loading="lazy">
                                    @else
                                        <i class="bi bi-film"></i>
                                    @endif
                                    <div class="pv-play-overlay"><i class="bi bi-play-circle-fill"></i></div>
                                </div>
                            </button>
                            <div class="pv-video-info">
                                <div class="text-truncate small fw-medium">{{ $v['nome'] }}</div>
                                <div class="small text-muted">{{ $v['duracao'] }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Modal de preview fullscreen --}}
                <div class="modal fade" id="modal-video-preview" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-fullscreen modal-dialog-centered m-0">
                        <div class="modal-content pv-player-modal">
                            <div class="pv-player-topbar">
                                <div class="d-flex align-items-center gap-3">
                                    <button type="button" class="btn btn-outline-light btn-sm" data-bs-dismiss="modal">
                                        <i class="bi bi-x-lg"></i> Fechar
                                    </button>
                                    <div>
                                        <div class="fw-semibold" id="pv-player-title">—</div>
                                        <div class="small opacity-75" id="pv-player-pos">—</div>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="text-end">
                                        <div class="small opacity-75">Selecionados</div>
                                        <div class="fw-bold"><span id="pv-player-count">0</span>{{ $gratis ? '' : ' · R$ <span id="pv-player-total">0,00</span>' }}</div>
                                    </div>
                                    <button type="button" class="btn btn-dark-panda btn-sm" id="pv-player-checkout" disabled>
                                        <i class="bi bi-cart-check me-1"></i>{{ $gratis ? 'Baixar' : 'Ir para checkout' }}
                                    </button>
                                </div>
                            </div>

                            <div class="pv-player-stage">
                                <button type="button" class="pv-player-nav pv-player-prev" id="pv-player-prev" title="Anterior">
                                    <i class="bi bi-chevron-left"></i>
                                </button>
                                <video id="pv-player-video" controls playsinline preload="metadata"
                                       style="max-width:100%; max-height:100%; background:#000;"></video>
                                <button type="button" class="pv-player-nav pv-player-next" id="pv-player-next" title="Próximo">
                                    <i class="bi bi-chevron-right"></i>
                                </button>
                            </div>

                            <div class="pv-player-bottombar">
                                <button type="button" class="btn btn-outline-light" id="pv-player-toggle">
                                    <i class="bi bi-plus-lg me-1"></i>Adicionar ao pedido
                                </button>
                                <div class="small opacity-75" id="pv-player-name">—</div>
                            </div>
                        </div>
                    </div>
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
