@php $rota = request()->route()?->getName() ?? ''; @endphp
<nav class="panda-navbar navbar navbar-expand-lg">
    <div class="container-fluid px-md-5">
        <a class="navbar-brand d-flex align-items-center gap-2" href="{{ route('cliente.dashboard') }}">
            <span style="font-size: 1.4rem;">🐼</span>
            <span>{{ config('app.name') }}</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navCliente">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navCliente">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'cliente.dashboard') ? 'active' : '' }}" href="{{ route('cliente.dashboard') }}">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'cliente.eventos') ? 'active' : '' }}" href="{{ route('cliente.eventos.index') }}">Eventos</a></li>
                <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'cliente.albuns') ? 'active' : '' }}" href="{{ route('cliente.albuns.index') }}">Álbuns</a></li>
                <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'cliente.pedidos') ? 'active' : '' }}" href="{{ route('cliente.pedidos.index') }}">Pedidos</a></li>
                <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'cliente.relatorio') ? 'active' : '' }}" href="{{ route('cliente.relatorio.index') }}">Relatório</a></li>
            </ul>
            <div class="dropdown">
                <button class="btn d-flex align-items-center gap-2" data-bs-toggle="dropdown">
                    <i class="bi bi-person-circle fs-5"></i>
                    <span>{{ auth()->user()->name }}</span>
                    <i class="bi bi-chevron-down small"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><span class="dropdown-item-text small text-muted">{{ auth()->user()->email }}</span></li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}">@csrf
                            <button type="submit" class="dropdown-item text-danger">Sair</button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</nav>
