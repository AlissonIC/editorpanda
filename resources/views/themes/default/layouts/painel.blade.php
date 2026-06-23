<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('titulo', 'Painel') · {{ config('app.name') }}{{ auth()->user()->isAdmin() ? ' Admin' : '' }}</title>
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
    @stack('styles')
</head>
<body>
    @include('theme::partials.navbar')

    <main class="container-fluid py-4 px-md-5">
        @yield('conteudo')
    </main>

    <script>
        window.userIsAdmin = @json(auth()->user()->isAdmin());
    </script>

    @stack('scripts')
</body>
</html>
