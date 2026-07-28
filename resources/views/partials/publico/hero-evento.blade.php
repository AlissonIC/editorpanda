{{--
    Hero padronizado do evento (usado nas páginas públicas de evento E álbum).
    Requer $evento (App\Models\Evento) e opcionalmente $subtituloExtra (string).

    - Background: foto principal do evento (fallback: qualquer thumb de vídeo)
    - Header: "Evento X · Cidade / Estado · Data" sempre exibido
    - Logo de branding (se cadastrada) no canto esquerdo
--}}
@php $capaUrl = $evento->fotoPrincipalUrl(); @endphp
<section class="pv-hero {{ $capaUrl ? 'pv-hero-capa' : '' }}"
         @if($capaUrl) style="background-image: linear-gradient(rgba(0,0,0,.45), rgba(0,0,0,.6)), url('{{ $capaUrl }}');" @endif>
    <div class="container py-5">
        <div class="d-flex align-items-center flex-wrap gap-4">
            @if($evento->logo_url)
                <img src="{{ $evento->logo_url }}" alt="Logo" class="pv-hero-logo">
            @endif
            <div class="flex-grow-1">
                <div class="text-uppercase small mb-1 {{ $capaUrl ? 'text-white-50' : 'text-muted' }}">Evento</div>
                <h1 class="fw-bold mb-1 {{ $capaUrl ? 'text-white' : '' }}">{{ $evento->nome }}</h1>
                <div class="{{ $capaUrl ? 'text-white-50' : 'text-muted' }}">
                    @if($evento->localizacao_cidade || $evento->localizacao_estado)
                        <i class="bi bi-geo-alt me-1"></i>{{ trim($evento->localizacao_cidade . ' / ' . $evento->localizacao_estado, ' /') }}
                    @endif
                    @if($evento->data)
                        @if($evento->localizacao_cidade || $evento->localizacao_estado)<span class="mx-2">·</span>@endif
                        <i class="bi bi-calendar me-1"></i>{{ $evento->data->format('d/m/Y') }}
                    @endif
                </div>
                @if(! empty($subtituloExtra))
                    <div class="mt-2 {{ $capaUrl ? 'text-white-50' : 'text-muted' }}">{{ $subtituloExtra }}</div>
                @endif
            </div>
            <a href="{{ route('publico.acesso') }}" class="btn {{ $capaUrl ? 'btn-light' : 'btn-dark' }} rounded-pill px-3">
                <i class="bi bi-bag-check me-1"></i> Já comprei
            </a>
        </div>
    </div>
</section>
