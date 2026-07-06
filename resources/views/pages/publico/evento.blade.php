@extends('theme::layouts.publico-produto')

@section('titulo', $evento->nome . ' — ' . config('app.name'))

@section('conteudo')
<section class="pv-hero">
    <div class="container py-5">
        <div class="d-flex align-items-center flex-wrap gap-4">
            @if($evento->logo_url)
                <img src="{{ $evento->logo_url }}" alt="Logo" class="pv-hero-logo">
            @endif
            <div class="flex-grow-1">
                <div class="text-uppercase text-muted small mb-1">Evento</div>
                <h1 class="fw-bold mb-1">{{ $evento->nome }}</h1>
                <div class="text-muted">
                    @if($evento->localizacao_cidade || $evento->localizacao_estado)
                        <i class="bi bi-geo-alt me-1"></i>{{ trim($evento->localizacao_cidade . ' / ' . $evento->localizacao_estado, ' /') }}
                    @endif
                    @if($evento->data)
                        <span class="mx-2">·</span>
                        <i class="bi bi-calendar me-1"></i>{{ $evento->data->format('d/m/Y') }}
                    @endif
                </div>
            </div>
            <a href="{{ route('publico.acesso') }}" class="btn btn-dark rounded-pill px-3">
                <i class="bi bi-bag-check me-1"></i> Já comprei
            </a>
        </div>
    </div>
</section>

<section class="container py-5">
    <h2 class="fw-bold mb-4">Álbuns disponíveis</h2>

    @if($albuns->isEmpty())
        <div class="pv-empty">
            <i class="bi bi-images"></i>
            <p>Nenhum álbum publicado ainda.</p>
        </div>
    @else
        <div class="row g-4">
            @foreach($albuns as $album)
                @php
                    $preco = $album->preco_por_video ?? $precoEvento;
                @endphp
                <div class="col-6 col-md-4 col-lg-3">
                    <a href="{{ route('publico.album.show', $album->slug) }}" class="pv-album-card text-decoration-none">
                        <div class="pv-album-cover">
                            @if($album->capa_path)
                                <img src="{{ asset('storage/' . $album->capa_path) }}" alt="">
                            @else
                                <i class="bi bi-collection"></i>
                            @endif
                        </div>
                        <div class="pv-album-body">
                            <div class="fw-semibold text-dark text-truncate">{{ $album->nome }}</div>
                            @if($album->subtitulo)
                                <div class="small text-muted text-truncate">{{ $album->subtitulo }}</div>
                            @endif
                            <div class="d-flex justify-content-between align-items-center mt-2 small">
                                <span class="text-muted"><i class="bi bi-film me-1"></i>{{ $album->videos_count }} vídeos</span>
                                <span class="fw-bold text-dark">R$ {{ number_format($preco, 2, ',', '.') }} /vídeo</span>
                            </div>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    @endif
</section>
@endsection
