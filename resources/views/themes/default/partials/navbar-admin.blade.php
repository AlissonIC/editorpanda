@php $rota = request()->route()?->getName() ?? ''; @endphp
<nav class="panda-navbar navbar navbar-expand-lg">
    <div class="container-fluid px-md-5">
        <a class="navbar-brand d-flex align-items-center gap-2" href="{{ route('admin.dashboard') }}">
            <span style="font-size: 1.4rem;">🐼</span>
            <span>{{ config('app.name') }} <span class="text-muted fw-normal">Admin</span></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navAdmin">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navAdmin">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'admin.dashboard') ? 'active' : '' }}" href="{{ route('admin.dashboard') }}">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'admin.usuarios') ? 'active' : '' }}" href="{{ route('admin.usuarios.index') }}">Usuários</a></li>
                <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'admin.financeiro') ? 'active' : '' }}" href="{{ route('admin.financeiro.index') }}">Financeiro</a></li>
                <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'admin.processamento') ? 'active' : '' }}" href="{{ route('admin.processamento.index') }}">Processamento</a></li>
                <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'admin.eventos') ? 'active' : '' }}" href="{{ route('admin.eventos.index') }}">Eventos</a></li>
                <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'admin.albuns') ? 'active' : '' }}" href="{{ route('admin.albuns.index') }}">Álbuns</a></li>
                <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'admin.pedidos') ? 'active' : '' }}" href="{{ route('admin.pedidos.index') }}">Pedidos</a></li>
                <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'admin.leads') ? 'active' : '' }}" href="{{ route('admin.leads.index') }}">Leads</a></li>
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
