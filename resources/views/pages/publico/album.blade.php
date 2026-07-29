@extends('theme::layouts.publico-produto')

@section('titulo', $album->nome . ' — ' . $album->evento->nome)

@section('conteudo')
{{-- Hero padronizado do evento (mesmo componente da página do evento) --}}
@include('partials.publico.hero-evento', [
    'evento' => $album->evento,
    'subtituloExtra' => 'Álbum: ' . $album->nome . ($album->subtitulo ? ' — ' . $album->subtitulo : ''),
])

{{-- Voltar pro evento --}}
<section class="container pt-3">
    <a href="{{ route('publico.evento.show', $album->evento->slug) }}" class="text-muted text-decoration-none small">
        <i class="bi bi-arrow-left me-1"></i> Voltar para {{ $album->evento->nome }}
    </a>
</section>

@php $gratis = $preco <= 0; @endphp
<section class="container py-4">
    @php $descontos = $album->descontosQuantidadeEfetivos(); @endphp
    <div
        id="album-app"
        class="row g-4"
        data-checkout-url="{{ route('publico.checkout.store', $album->slug) }}"
        data-videos-url="{{ route('publico.album.videos', $album->slug) }}"
        data-preco="{{ $preco }}"
        data-gratis="{{ $gratis ? '1' : '0' }}"
        data-descontos="{{ json_encode($descontos) }}"
        data-videos-total="{{ $videosTotal }}"
        data-prox-cursor="{{ $proxCursor ?? '' }}"
    >
        <div class="col-lg-8">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="fw-bold mb-0">Vídeos</h4>
                <div class="small text-muted">
                    {{ $videosTotal }} vídeos ·
                    @if($gratis)
                        <span class="text-success fw-semibold">Grátis</span>
                    @else
                        R$ {{ number_format($preco, 2, ',', '.') }} cada
                    @endif
                </div>
            </div>

            @if($videosTotal === 0)
                <div class="pv-empty">
                    <i class="bi bi-film"></i>
                    <p>Nenhum vídeo neste álbum ainda.</p>
                </div>
            @else
                <div class="pv-video-grid" id="pv-video-grid" data-videos="{{ json_encode($videos) }}">
                    @foreach($videos as $i => $v)
                        <div class="pv-video-card" data-video-index="{{ $i }}" data-video-id="{{ $v['id'] }}">
                            <label class="pv-video-check-wrap">
                                <input type="checkbox" class="pv-video-check" value="{{ $v['id'] }}">
                                <div class="pv-check-badge"><i class="bi bi-check-lg"></i></div>
                            </label>
                            <button type="button" class="pv-video-play-btn" data-video-index="{{ $i }}"
                                    title="Pré-visualizar">
                                <div class="pv-video-thumb">
                                    @if($v['thumbnail_url'])
                                        <img src="{{ $v['thumbnail_url'] }}" alt="" loading="lazy" decoding="async">
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
                {{-- Sentinel do infinite scroll: quando esta div aparece no viewport,
                     o JS busca a próxima página via /a/{slug}/videos?after=<cursor>. --}}
                <div id="pv-video-sentinel" class="text-center text-muted small py-3" style="display:none;">
                    <i class="bi bi-arrow-clockwise"></i> Carregando mais…
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
                                        <div class="fw-bold">
                                            <span id="pv-player-count">0</span>
                                            @if(! $gratis)
                                                · R$ <span id="pv-player-total">0,00</span>
                                            @endif
                                        </div>
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
                                {{-- Player hardened: sem download, sem PiP, sem cast/AirPlay,
                                     sem menu de contexto. Não é impossível burlar (screen-record
                                     sempre é possível) — a proteção real são as watermarks
                                     queimadas dentro do próprio vídeo. --}}
                                <video id="pv-player-video"
                                       controls playsinline preload="metadata"
                                       controlslist="nodownload noremoteplayback noplaybackrate"
                                       disablepictureinpicture
                                       oncontextmenu="return false;"
                                       style="max-width:100%; max-height:100%; background:#000; display:none;"></video>
                                {{-- Player de IMAGEM: usado quando o item é foto (JPG),
                                     não vídeo. Alternado via JS baseado em v.is_imagem. --}}
                                <img id="pv-player-image"
                                     alt=""
                                     oncontextmenu="return false;"
                                     style="max-width:100%; max-height:100%; background:#000; display:none; object-fit:contain;">
                                {{-- Overlay de aviso quando detectamos tentativa de captura
                                     (aba escondida, janela perdeu foco, PrintScreen). Não
                                     impede captura de fato — o browser não expõe API pra isso
                                     sem DRM — mas serve como deterrent/branding. --}}
                                <div class="pv-protection-overlay" id="pv-protection-overlay">
                                    <div class="pv-protection-warn">
                                        <i class="bi bi-shield-lock-fill"></i>
                                        <h3>Conteúdo protegido</h3>
                                        <p class="mb-0">Capturas de tela e gravações são proibidas.<br>
                                        As imagens contêm marcações que identificam o infrator.</p>
                                    </div>
                                </div>
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
                        <div class="d-flex justify-content-between text-muted small mt-1">
                            <span>Subtotal</span>
                            <span>R$ <span id="pv-subtotal">0,00</span></span>
                        </div>
                        <div class="d-flex justify-content-between small mt-1 pv-desconto-row d-none" id="pv-desconto-row">
                            <span class="text-success"><i class="bi bi-tag-fill me-1"></i><span id="pv-desconto-label">Desconto</span></span>
                            <span class="text-success">− R$ <span id="pv-desconto">0,00</span></span>
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

                    @if(! $gratis && ! empty($descontos))
                        <div class="mt-3 small">
                            <div class="text-muted mb-1"><i class="bi bi-tag me-1"></i>Descontos por quantidade:</div>
                            <ul class="list-unstyled mb-0 ps-1">
                                @foreach($descontos as $d)
                                    <li class="small">
                                        <span class="text-dark">{{ (int) $d['qtd'] }}+ vídeos</span>
                                        <span class="text-success fw-semibold ms-1">−{{ number_format((float) $d['percentual'], 0) }}%</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>

                <form id="pv-checkout-form" novalidate autocomplete="on"
                      @unless($gratis) data-verificar-url="{{ route('publico.checkout.verificar-email', $album->slug) }}" @endunless>
                    @csrf

                    <div class="mb-2">
                        <label class="form-label small" for="pv-form-nome">Seu nome</label>
                        <input type="text" name="nome" id="pv-form-nome"
                               class="form-control"
                               autocomplete="name"
                               autocapitalize="words"
                               spellcheck="false"
                               required minlength="2" maxlength="120">
                        <div class="invalid-feedback">Informe seu nome.</div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small" for="pv-form-email">E-mail</label>
                        <input type="email" name="email" id="pv-form-email"
                               class="form-control"
                               autocomplete="email"
                               inputmode="email"
                               autocapitalize="off"
                               spellcheck="false"
                               required maxlength="180">
                        <div class="invalid-feedback">Informe um e-mail válido.</div>
                    </div>

                    {{-- Overlay de pré-checagem: preenche via JS quando o email
                         é válido e há itens já comprados por esse comprador. --}}
                    <div id="pv-pre-check" class="alert alert-warning small mb-3 d-none" role="status">
                        <div class="fw-semibold mb-1" id="pv-pre-check-title">—</div>
                        <div class="text-muted mb-2" id="pv-pre-check-msg">—</div>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-sm btn-dark" id="pv-pre-check-mail">
                                <i class="bi bi-envelope me-1"></i>Receber por e-mail
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-dark" id="pv-pre-check-remove">
                                <i class="bi bi-cart-x me-1"></i>Remover do carrinho
                            </button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small" for="pv-form-whats">WhatsApp <span class="text-muted">(opcional)</span></label>
                        <input type="tel" name="whatsapp" id="pv-form-whats"
                               class="form-control"
                               autocomplete="tel-national"
                               inputmode="tel"
                               placeholder="(11) 99999-9999"
                               maxlength="20">
                    </div>

                    @if(! $gratis)
                        {{-- Método de pagamento — pré-seleciona a aba do modal.
                             Layout de tiles pra ficar tocável no mobile e claro visualmente. --}}
                        <div class="mb-3">
                            <label class="form-label small">Forma de pagamento</label>
                            <div class="pv-metodo-tiles">
                                <label class="pv-metodo-tile">
                                    <input type="radio" name="metodo" value="pix" checked>
                                    <span class="pv-metodo-tile-body">
                                        <i class="bi bi-qr-code"></i>
                                        <span class="fw-semibold small">PIX</span>
                                        <span class="text-muted" style="font-size:.7rem;">Aprovação em segundos</span>
                                    </span>
                                </label>
                                <label class="pv-metodo-tile">
                                    <input type="radio" name="metodo" value="cartao">
                                    <span class="pv-metodo-tile-body">
                                        <i class="bi bi-credit-card"></i>
                                        <span class="fw-semibold small">Cartão</span>
                                        <span class="text-muted" style="font-size:.7rem;">Até 12x</span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        {{-- Cupom colapsável — reduz ruído visual pra maioria que não usa. --}}
                        <div class="mb-3">
                            <a class="small text-muted text-decoration-none" data-bs-toggle="collapse"
                               href="#pv-cupom-collapse" role="button">
                                <i class="bi bi-tag me-1"></i>Tem cupom de desconto?
                            </a>
                            <div class="collapse mt-2" id="pv-cupom-collapse">
                                <input type="text" name="codigo_cupom" id="pv-form-cupom"
                                       class="form-control text-uppercase"
                                       autocapitalize="characters"
                                       autocomplete="off"
                                       spellcheck="false"
                                       placeholder="Digite o código" maxlength="60">
                            </div>
                        </div>
                    @endif

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

@unless($gratis)
    {{-- Modal de PAGAMENTO — abre após submit do checkout. Duas abas: PIX e Cartão.
         O JS decide qual mostrar por padrão, aciona o MP Bricks, faz polling de status,
         e redireciona pra /pedido/{id} quando aprovado. --}}
    <div class="modal fade" id="modal-pagamento" tabindex="-1" aria-hidden="true"
         data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Pagamento — <span id="pv-pag-total">R$ 0,00</span></h5>
                    <button type="button" class="btn-close" id="pv-pag-close" title="Cancelar"></button>
                </div>
                <div class="modal-body">
                    <ul class="nav nav-pills mb-3 justify-content-center" id="pv-pag-tabs" role="tablist">
                        <li class="nav-item">
                            <button type="button" class="nav-link active" data-tab="pix">
                                <i class="bi bi-qr-code me-1"></i>PIX
                            </button>
                        </li>
                        <li class="nav-item">
                            <button type="button" class="nav-link" data-tab="cartao">
                                <i class="bi bi-credit-card me-1"></i>Cartão de crédito
                            </button>
                        </li>
                    </ul>

                    {{-- Aba PIX --}}
                    <div id="pv-pag-pix" class="pv-pag-tab-content">
                        <div id="pv-pag-pix-loading" class="text-center py-4">
                            <div class="spinner-border text-dark" role="status"></div>
                            <div class="small text-muted mt-2">Gerando QR Code…</div>
                        </div>
                        <div id="pv-pag-pix-content" style="display:none;">
                            <div class="text-center mb-3">
                                <img id="pv-pag-pix-qr" alt="QR Code PIX"
                                     style="max-width:220px; border:1px solid #eee; padding:8px; border-radius:.5rem;">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">Copia e cola</label>
                                <div class="input-group">
                                    <input type="text" id="pv-pag-pix-codigo" class="form-control font-monospace small" readonly>
                                    <button type="button" class="btn btn-outline-secondary" id="pv-pag-pix-copiar">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="alert alert-info small mb-0 mt-3">
                                <i class="bi bi-info-circle me-1"></i>
                                Abra o app do seu banco, escolha PIX e cole o código
                                (ou escaneie o QR). A confirmação é automática — pode
                                deixar esta aba aberta. <span id="pv-pag-pix-timer" class="fw-semibold"></span>
                            </div>
                        </div>
                    </div>

                    {{-- Aba Cartão --}}
                    <div id="pv-pag-cartao" class="pv-pag-tab-content" style="display:none;">
                        <div id="cardPaymentBrick_container"></div>
                    </div>

                    {{-- Estado global (aguardando / aprovado / rejeitado) --}}
                    <div id="pv-pag-status" class="alert d-none mt-3" role="status"></div>
                </div>
            </div>
        </div>
    </div>
@endunless
@endsection

@push('scripts')
    @vite('resources/js/pages/publico/album.js')
@endpush
