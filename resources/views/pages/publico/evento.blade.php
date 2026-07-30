@extends('theme::layouts.publico-produto')

@section('titulo', $evento->nome . ' — ' . config('app.name'))

@section('conteudo')
@include('partials.publico.hero-evento', ['evento' => $evento])

{{-- Descrição do evento --}}
@if($evento->descricao)
    <section class="container py-4">
        <div class="mx-auto" style="max-width: 720px;">
            <p class="text-muted lh-lg mb-0" style="white-space: pre-line;">{{ $evento->descricao }}</p>
        </div>
    </section>
@endif

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
                    $gratis = $preco <= 0;
                    $capaAlbum = $album->fotoPrincipalUrl();
                    $ehImg = $album->ehAlbumImagem();
                    $rotuloContagem = $ehImg ? 'fotos' : 'vídeos';
                    $iconeContagem = $ehImg ? 'bi-image' : 'bi-film';
                    $rotuloUnidade = $ehImg ? '/foto' : '/vídeo';
                @endphp
                <div class="col-6 col-md-4 col-lg-3">
                    <a href="{{ route('publico.album.show', $album->slug) }}" class="pv-album-card text-decoration-none">
                        <div class="pv-album-cover">
                            @if($capaAlbum)
                                <img src="{{ $capaAlbum }}" alt="" loading="lazy">
                            @else
                                <i class="bi {{ $ehImg ? 'bi-images' : 'bi-collection-play' }}"></i>
                            @endif
                            @if($gratis)
                                <span class="pv-album-gratis">Grátis</span>
                            @endif
                        </div>
                        <div class="pv-album-body">
                            <div class="fw-semibold text-dark text-truncate">{{ $album->nome }}</div>
                            @if($album->subtitulo)
                                <div class="small text-muted text-truncate">{{ $album->subtitulo }}</div>
                            @endif
                            <div class="d-flex justify-content-between align-items-center mt-2 small">
                                <span class="text-muted"><i class="bi {{ $iconeContagem }} me-1"></i>{{ $album->videos_count }} {{ $rotuloContagem }}</span>
                                @if($gratis)
                                    <span class="fw-bold text-success">Grátis</span>
                                @else
                                    <span class="fw-bold text-dark">R$ {{ number_format($preco, 2, ',', '.') }} {{ $rotuloUnidade }}</span>
                                @endif
                            </div>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    @endif
</section>
@endsection
