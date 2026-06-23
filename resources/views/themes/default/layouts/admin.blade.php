<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('titulo', 'Painel') · {{ config('app.name') }} Admin</title>
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
    @stack('styles')
</head>
<body>
    @include('theme::partials.navbar-admin')

    <main class="container-fluid py-4 px-md-5">
        @yield('conteudo')
    </main>

    @stack('scripts')
</body>
</html>
