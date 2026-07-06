<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('titulo', config('app.name'))</title>
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="pv-body">

<nav class="pv-navbar">
    <div class="container d-flex align-items-center justify-content-between py-3">
        <a href="{{ url('/') }}" class="d-flex align-items-center gap-2 text-decoration-none">
            <span style="font-size: 1.5rem;">🐼</span>
            <span class="fw-bold text-dark">{{ config('app.name') }}</span>
        </a>
        <div class="d-flex gap-2 align-items-center">
            @auth('comprador')
                <a href="{{ route('publico.minhas-compras') }}" class="btn btn-sm btn-outline-dark">
                    <i class="bi bi-bag-check me-1"></i> Minhas compras
                </a>
                <form method="POST" action="{{ route('publico.acesso.logout') }}">@csrf
                    <button class="btn btn-sm btn-link text-muted">Sair</button>
                </form>
            @else
                <a href="{{ route('publico.acesso') }}" class="btn btn-sm btn-outline-dark">
                    <i class="bi bi-envelope me-1"></i> Acessar minhas compras
                </a>
            @endauth
        </div>
    </div>
</nav>

<main>
    @yield('conteudo')
</main>

<footer class="text-center text-muted small py-4">
    © {{ date('Y') }} {{ config('app.name') }}
</footer>

@stack('scripts')
</body>
</html>
