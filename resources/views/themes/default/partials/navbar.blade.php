@php
    $rota = request()->route()?->getName() ?? '';
    $isAdmin = auth()->user()->isAdmin();
@endphp
<nav class="panda-navbar navbar navbar-expand-lg">
    <div class="container-fluid px-md-5">
        <a class="navbar-brand d-flex align-items-center gap-2" href="{{ route('painel.dashboard') }}">
            <span style="font-size: 1.4rem;">🐼</span>
            <span>
                {{ config('app.name') }}
                @if($isAdmin)<span class="text-muted fw-normal">Admin</span>@endif
            </span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navPainel">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navPainel">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'painel.dashboard') ? 'active' : '' }}" href="{{ route('painel.dashboard') }}">Dashboard</a></li>

                @if($isAdmin)
                    <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'painel.usuarios') ? 'active' : '' }}" href="{{ route('painel.usuarios.index') }}">Usuários</a></li>
                    <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'painel.financeiro') ? 'active' : '' }}" href="{{ route('painel.financeiro.index') }}">Financeiro</a></li>
                    <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'painel.processamento') ? 'active' : '' }}" href="{{ route('painel.processamento.index') }}">Processamento</a></li>
                @endif

                <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'painel.eventos') ? 'active' : '' }}" href="{{ route('painel.eventos.index') }}">Eventos</a></li>
                <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'painel.albuns') ? 'active' : '' }}" href="{{ route('painel.albuns.index') }}">Álbuns</a></li>
                <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'painel.pedidos') ? 'active' : '' }}" href="{{ route('painel.pedidos.index') }}">Pedidos</a></li>

                @if($isAdmin)
                    <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'painel.planos') ? 'active' : '' }}" href="{{ route('painel.planos.index') }}">Planos</a></li>
                    <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'painel.leads') ? 'active' : '' }}" href="{{ route('painel.leads.index') }}">Leads</a></li>
                    <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'painel.configuracoes') ? 'active' : '' }}" href="{{ route('painel.configuracoes.index') }}">Configurações</a></li>
                @else
                    <li class="nav-item"><a class="nav-link {{ str_starts_with($rota, 'painel.relatorio') ? 'active' : '' }}" href="{{ route('painel.relatorio.index') }}">Relatório</a></li>
                @endif
            </ul>
            @php $u = auth()->user(); @endphp
            <div class="dropdown">
                <button class="btn d-flex align-items-center gap-2" data-bs-toggle="dropdown">
                    @if($u->foto_url)
                        <img data-nav-avatar src="{{ $u->foto_url }}" alt=""
                             style="width:32px;height:32px;border-radius:50%;object-fit:cover;border:1px solid #eaecf4;">
                    @else
                        <span class="d-inline-flex align-items-center justify-content-center"
                              style="width:32px;height:32px;border-radius:50%;background:#ebe9fd;color:#7367f0;font-size:.75rem;font-weight:700;">
                            {{ $u->iniciais }}
                        </span>
                    @endif
                    <span>{{ $u->nome }}</span>
                    <i class="bi bi-chevron-down small"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><span class="dropdown-item-text small text-muted">{{ $u->email }}</span></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="{{ route('painel.perfil.edit') }}"><i class="bi bi-person me-2"></i>Meu Perfil</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}">@csrf
                            <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Sair</button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</nav>
