@extends('theme::layouts.cliente')

@section('titulo', 'Dashboard')

@section('conteudo')
<div class="panda-card mb-4">
    <h2 class="h4 mb-1 fw-bold">Dashboard</h2>
    <p class="text-muted mb-0">Bem-vindo ao painel de controle do {{ config('app.name') }}</p>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <x-theme::stat-card label="Saldo Disponível" value="R$ {{ number_format($saldo, 2, ',', '.') }}" icon="bi-wallet2" color="success" />
    </div>
    <div class="col-6 col-lg-3">
        <x-theme::stat-card label="Vendas do Mês" value="R$ {{ number_format($vendasMes, 2, ',', '.') }}" icon="bi-cash-stack" color="info" />
    </div>
    <div class="col-6 col-lg-3">
        <x-theme::stat-card label="Vídeos Vendidos" value="{{ $fotosVendidas }}" icon="bi-camera-video" color="primary" />
    </div>
    <div class="col-6 col-lg-3">
        <x-theme::stat-card label="Pedidos Pendentes" value="{{ $pedidosPendentes }}" icon="bi-clock-history" color="warning" />
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="panda-card h-100">
            <h3 class="h6 fw-bold mb-3">Álbuns Recentes</h3>
            @forelse($albunsRecentes as $album)
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                    <div>
                        <div class="fw-semibold">{{ $album->nome }}</div>
                        <div class="text-muted small">{{ ucfirst($album->status) }}</div>
                    </div>
                    <span class="status-badge {{ $album->status }}">{{ ucfirst($album->status) }}</span>
                </div>
            @empty
                <p class="text-muted small mb-0">Nenhum álbum cadastrado ainda.</p>
            @endforelse
            <a href="{{ route('cliente.albuns.index') }}" class="d-inline-block mt-3 small text-decoration-none">Ver todos os álbuns →</a>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="panda-card h-100">
            <h3 class="h6 fw-bold mb-3">Pedidos Recentes</h3>
            @forelse($pedidosRecentes as $pedido)
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                    <div>
                        <div class="fw-semibold">{{ $pedido->comprador_email ?? 'Anônimo' }}</div>
                        <div class="text-muted small">{{ $pedido->created_at?->format('d/m/Y H:i') }}</div>
                    </div>
                    <div class="text-end">
                        <div class="fw-semibold">R$ {{ number_format((float) $pedido->total, 2, ',', '.') }}</div>
                        <span class="status-badge {{ $pedido->status }}">{{ ucfirst($pedido->status) }}</span>
                    </div>
                </div>
            @empty
                <p class="text-muted small mb-0">Nenhum pedido recebido ainda.</p>
            @endforelse
        </div>
    </div>
</div>

<div class="panda-card">
    <h3 class="h6 fw-bold mb-3">Ações Rápidas</h3>
    <div class="row g-3">
        <div class="col-6 col-md-3">
            <a href="{{ route('cliente.eventos.index') }}" class="d-block text-decoration-none text-dark border rounded p-3 h-100">
                <i class="bi bi-calendar-plus text-primary fs-4"></i>
                <div class="fw-semibold mt-2">Novo Evento</div>
                <div class="text-muted small">Criar evento</div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="{{ route('cliente.albuns.index') }}" class="d-block text-decoration-none text-dark border rounded p-3 h-100">
                <i class="bi bi-images text-info fs-4"></i>
                <div class="fw-semibold mt-2">Novo Álbum</div>
                <div class="text-muted small">Criar álbum de vídeos</div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="{{ route('cliente.pedidos.index') }}" class="d-block text-decoration-none text-dark border rounded p-3 h-100">
                <i class="bi bi-receipt text-warning fs-4"></i>
                <div class="fw-semibold mt-2">Pedidos</div>
                <div class="text-muted small">Histórico de vendas</div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="{{ route('cliente.relatorio.index') }}" class="d-block text-decoration-none text-dark border rounded p-3 h-100">
                <i class="bi bi-bar-chart text-success fs-4"></i>
                <div class="fw-semibold mt-2">Relatório</div>
                <div class="text-muted small">Análise de vendas</div>
            </a>
        </div>
    </div>
</div>
@endsection
