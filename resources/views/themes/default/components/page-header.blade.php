@props(['titulo', 'subtitulo' => null])
<div class="d-flex flex-column flex-md-row align-items-md-end justify-content-between mb-4 gap-3">
    <div>
        <h1 class="h3 mb-1 fw-bold">{{ $titulo }}</h1>
        @isset($subtitulo)<p class="text-muted mb-0">{{ $subtitulo }}</p>@endisset
    </div>
    <div class="d-flex gap-2">
        {{ $slot }}
    </div>
</div>
