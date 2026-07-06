@extends('theme::layouts.public')

@section('titulo', 'Editor Panda — Plataforma de Gestão de Mídia')

@section('conteudo')
<div class="landing">

    {{-- ============ NAVBAR ============ --}}
    <nav class="landing-nav">
        <div class="container d-flex align-items-center justify-content-between py-3">
            <a href="#" class="brand d-flex align-items-center gap-2 text-decoration-none">
                <span class="brand-mark">🐼</span>
                <span class="brand-name">Editor Panda</span>
            </a>
            <ul class="nav-links d-none d-lg-flex align-items-center gap-4 m-0 p-0">
                <li><a href="#sobre">Sobre</a></li>
                <li><a href="#funcionalidades">Funcionalidades</a></li>
                <li><a href="#beneficios">Benefícios</a></li>
                <li><a href="#planos">Planos</a></li>
                <li><a href="#contato">Contato</a></li>
            </ul>
            <a href="{{ route('login') }}" class="btn btn-dark rounded-pill px-3 py-2 fw-semibold">Dashboard</a>
        </div>
    </nav>

    {{-- ============ HERO ============ --}}
    <section class="hero">
        <div class="container text-center py-5">
            <span class="hero-badge">
                <i class="bi bi-stars"></i> Câmera lenta + Marca d'água
            </span>
            <h1 class="hero-title mt-4">
                A Única Plataforma com <br>
                <span class="text-white-50 fw-normal">Processamento Automático</span>
            </h1>
            <p class="hero-sub mx-auto mt-3">
                Câmera lenta automática + logo do fotógrafo automático.<br>
                Seus vídeos ficam profissionais sem você fazer nada!
            </p>

            <div class="row justify-content-center g-3 mt-4">
                <div class="col-md-5 col-lg-4">
                    <div class="hero-card">
                        <div class="hero-card-icon"><i class="bi bi-camera-video"></i></div>
                        <h5>Câmera lenta automática</h5>
                        <p>Seus vídeos ganham câmera lenta profissional automaticamente. Sem configuração, sem trabalho extra.</p>
                    </div>
                </div>
                <div class="col-md-5 col-lg-4">
                    <div class="hero-card">
                        <div class="hero-card-icon"><i class="bi bi-fonts"></i></div>
                        <h5>Logo Automática</h5>
                        <p>Seu nome aparece automaticamente em todos os vídeos. Marca registrada sem esforço.</p>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-wrap justify-content-center gap-2 mt-4">
                <a href="{{ route('login') }}" class="btn btn-light rounded-pill px-4 py-2 fw-semibold">
                    Acessar Dashboard <i class="bi bi-arrow-right ms-1"></i>
                </a>
                <a href="#beneficios" class="btn btn-outline-light rounded-pill px-4 py-2 fw-semibold">
                    Por que escolher?
                </a>
            </div>

            <div class="d-flex flex-wrap justify-content-center gap-2 mt-4">
                <span class="hero-pill"><i class="bi bi-check-circle-fill text-success"></i> Menor taxa do mercado</span>
                <span class="hero-pill"><i class="bi bi-check-circle-fill text-success"></i> WhatsApp integrado</span>
                <span class="hero-pill"><i class="bi bi-check-circle-fill text-success"></i> Rotação automática</span>
            </div>
            <div class="d-flex flex-wrap justify-content-center gap-2 mt-2">
                <span class="hero-pill"><i class="bi bi-check-circle-fill text-success"></i> E muito mais</span>
            </div>
        </div>
    </section>

    {{-- ============ SOBRE ============ --}}
    <section id="sobre" class="section-light py-5">
        <div class="container py-4">
            <div class="text-center mb-5">
                <h2 class="section-title">Sobre o Editor Panda</h2>
                <p class="section-lead mx-auto">
                    A plataforma perfeita para fotógrafos e videomakers venderem seus trabalhos.
                    Simples, rápida e com tudo que você precisa para ganhar dinheiro.
                </p>
            </div>
            <div class="row align-items-center g-4">
                <div class="col-lg-6">
                    <h4 class="fw-bold mb-3">Como Funciona</h4>
                    <p class="text-muted">
                        Você paga uma mensalidade + uma pequena porcentagem das vendas. Seus clientes
                        compram direto na plataforma e você recebe o dinheiro na hora.
                    </p>
                    <p class="text-muted">
                        Quando um álbum fica pronto ou uma nova compra acontece, você recebe uma
                        notificação no seu WhatsApp. Simples assim!
                    </p>
                </div>
                <div class="col-lg-6">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon icon-success"><i class="bi bi-cash-coin"></i></div>
                            <div class="stat-value">R$ 99,90</div>
                            <div class="stat-label">Mensalidade a partir de</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon icon-info"><i class="bi bi-percent"></i></div>
                            <div class="stat-value">10%</div>
                            <div class="stat-label">Por venda</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon icon-primary"><i class="bi bi-shield-check"></i></div>
                            <div class="stat-value">99.9%</div>
                            <div class="stat-label">Uptime</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon icon-warning"><i class="bi bi-headset"></i></div>
                            <div class="stat-value">24/7</div>
                            <div class="stat-label">Suporte</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ============ FUNCIONALIDADES ============ --}}
    <section id="funcionalidades" class="section-gray py-5">
        <div class="container py-4">
            <div class="text-center mb-5">
                <h2 class="section-title">O que você ganha</h2>
                <p class="section-lead mx-auto">
                    Tudo que você precisa para vender suas mídias de forma profissional.
                </p>
            </div>
            <div class="row g-4">
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="bi bi-collection"></i></div>
                        <h5>Álbuns Organizados</h5>
                        <p>Crie álbuns para cada evento, organize suas fotos e vídeos. Configure preços e deixe tudo pronto para venda.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="bi bi-camera-video"></i></div>
                        <h5>Câmera lenta automática</h5>
                        <p>Seus vídeos ganham câmera lenta profissional automaticamente. Sem configuração, sem trabalho extra.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="bi bi-fonts"></i></div>
                        <h5>Logo Automática</h5>
                        <p>Seu nome aparece automaticamente em todos os vídeos. Marca registrada sem esforço.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="bi bi-whatsapp"></i></div>
                        <h5>WhatsApp integrado</h5>
                        <p>Receba notificações no WhatsApp quando um álbum ficar pronto ou quando um cliente fizer uma nova compra.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="bi bi-heart-pulse"></i></div>
                        <h5>Vendas Diretas</h5>
                        <p>Seus clientes compram direto na plataforma. Você recebe o dinheiro na hora e pode sacar 24 horas por dia.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card">
                        <div class="feature-icon"><i class="bi bi-arrow-repeat"></i></div>
                        <h5>Processamento Automático</h5>
                        <p>Câmera lenta configurável para cada vídeo, rotação automática das imagens e logo posicionado onde quiser.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ============ BENEFÍCIOS ============ --}}
    <section id="beneficios" class="section-light py-5">
        <div class="container py-4">
            <div class="text-center mb-5">
                <h2 class="section-title">Por que escolher o Editor Panda?</h2>
                <p class="section-lead mx-auto">
                    A plataforma mais simples e completa para fotógrafos e videomakers venderem seus trabalhos.
                </p>
            </div>
            <div class="row align-items-center g-4">
                <div class="col-lg-7">
                    <ul class="benefit-list list-unstyled m-0">
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            <div>
                                <strong>Ganhe Mais Dinheiro</strong>
                                <p>Venda direto para seus clientes sem intermediários. Todo o lucro é seu.</p>
                            </div>
                        </li>
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            <div>
                                <strong>Saque Imediato</strong>
                                <p>Receba seu dinheiro na hora e saque 24 horas por dia, sem valor mínimo.</p>
                            </div>
                        </li>
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            <div>
                                <strong>Notificações no WhatsApp</strong>
                                <p>Fique sabendo na hora quando um álbum ficar pronto ou uma venda acontecer.</p>
                            </div>
                        </li>
                        <li>
                            <i class="bi bi-check-circle-fill"></i>
                            <div>
                                <strong>Fácil de Começar</strong>
                                <p>Em poucos minutos você já está vendendo. Sem complicação, sem enrolação.</p>
                            </div>
                        </li>
                    </ul>
                </div>
                <div class="col-lg-5">
                    <div class="uptime-card">
                        <div class="uptime-value">99.9%</div>
                        <div class="uptime-title">Uptime Garantido</div>
                        <p class="text-muted mb-0">Sua plataforma sempre disponível quando você precisar.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ============ PLANOS ============ --}}
    @if($planos->isNotEmpty())
    <section id="planos" class="section-gray py-5">
        <div class="container py-4">
            <div class="text-center mb-5">
                <h2 class="section-title">Planos</h2>
                <p class="section-lead mx-auto">Escolha o plano ideal para o seu negócio.</p>
            </div>
            <div class="row justify-content-center g-4">
                @foreach($planos as $plano)
                    <div class="col-md-6 col-lg-4">
                        <div class="plan-card {{ $plano->popular ? 'plan-popular' : '' }}">
                            @if($plano->popular)
                                <span class="plan-badge">Popular</span>
                            @endif
                            <h4 class="fw-bold">{{ $plano->nome }}</h4>
                            @if($plano->descricao)
                                <p class="text-muted small">{{ $plano->descricao }}</p>
                            @endif
                            <div class="plan-price">
                                R$ {{ number_format((float) $plano->preco, 2, ',', '.') }}
                                <span>/mensal</span>
                            </div>
                            <ul class="plan-features list-unstyled">
                                <li><i class="bi bi-check2"></i> {{ $plano->armazenamento_gb }} GB de armazenamento</li>
                                <li><i class="bi bi-check2"></i> {{ number_format((float) $plano->taxa_por_venda, 2, ',', '.') }}% de taxa por venda</li>
                            </ul>
                            <a href="{{ route('login') }}" class="btn btn-dark w-100 rounded-pill py-2">Contratar</a>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    {{-- ============ CTA ============ --}}
    <section id="contato" class="cta-section text-center py-5">
        <div class="container py-4">
            <h2 class="cta-title">Pronto para começar a vender suas mídias?</h2>
            <p class="cta-sub mx-auto mt-3">
                Junte-se a fotógrafos e videomakers que já estão ganhando dinheiro com o Editor Panda.
            </p>
            <a href="{{ route('login') }}" class="btn btn-light rounded-pill px-4 py-2 fw-semibold mt-3">
                Acessar Dashboard
            </a>
        </div>
    </section>

    {{-- ============ FOOTER ============ --}}
    <footer class="landing-footer py-5">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="brand-mark">🐼</span>
                        <span class="brand-name">Editor Panda</span>
                    </div>
                    <p class="text-white-50">
                        A plataforma mais completa para fotógrafos e videomakers venderem seus trabalhos.
                        Organize, venda e receba seu dinheiro na hora.
                    </p>
                </div>
                <div class="col-6 col-lg-3 offset-lg-1">
                    <h6 class="footer-heading">Produto</h6>
                    <ul class="footer-list list-unstyled">
                        <li><a href="#funcionalidades">Funcionalidades</a></li>
                        <li><a href="#beneficios">Benefícios</a></li>
                        <li><a href="#planos">Planos</a></li>
                    </ul>
                </div>
                <div class="col-6 col-lg-3">
                    <h6 class="footer-heading">Suporte</h6>
                    <ul class="footer-list list-unstyled">
                        <li><a href="#contato">Central de Ajuda</a></li>
                        <li><a href="#contato">Contato</a></li>
                        <li><a href="#contato">Status</a></li>
                    </ul>
                </div>
            </div>
            <hr class="border-secondary mt-4 mb-3">
            <div class="text-white-50 small">
                © {{ date('Y') }} Editor Panda. Todos os direitos reservados.
            </div>
        </div>
    </footer>

</div>
@endsection
