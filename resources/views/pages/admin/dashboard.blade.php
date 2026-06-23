@extends('theme::layouts.admin')

@section('titulo', 'Dashboard')

@section('conteudo')
<div class="panda-card mb-4">
    <h2 class="h4 mb-1 fw-bold">Dashboard</h2>
    <p class="text-muted mb-0">Visão geral da plataforma</p>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <x-theme::stat-card label="Saldo dos Clientes" value="R$ {{ number_format($saldoTotal, 2, ',', '.') }}" icon="bi-wallet2" color="success" />
    </div>
    <div class="col-6 col-lg-3">
        <x-theme::stat-card label="Vendas do Mês" value="R$ {{ number_format($vendasMes, 2, ',', '.') }}" icon="bi-cash-stack" color="info" />
    </div>
    <div class="col-6 col-lg-3">
        <x-theme::stat-card label="Vídeos Processados" value="{{ $videosProcessados }}" icon="bi-camera-video" color="primary" />
    </div>
    <div class="col-6 col-lg-3">
        <x-theme::stat-card label="Pedidos Pendentes" value="{{ $pedidosPendentes }}" icon="bi-clock-history" color="warning" />
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="panda-card h-100">
            <h3 class="h6 fw-bold mb-3">Álbuns Recentes</h3>
            @forelse($albunsRecentes as $album)
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                    <div>
                        <div class="fw-semibold">{{ $album->nome }}</div>
                        <div class="text-muted small">{{ $album->user?->name }} · {{ $album->evento?->nome }}</div>
                    </div>
                    <span class="status-badge {{ $album->status }}">{{ ucfirst($album->status) }}</span>
                </div>
            @empty
                <p class="text-muted small mb-0">Nenhum álbum cadastrado ainda.</p>
            @endforelse
            <a href="{{ route('admin.albuns.index') }}" class="d-inline-block mt-3 small text-decoration-none">Ver todos os álbuns →</a>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="panda-card h-100">
            <h3 class="h6 fw-bold mb-3">Ações rápidas</h3>
            <div class="d-grid gap-2">
                <a href="{{ route('admin.usuarios.index') }}" class="btn btn-outline-secondary text-start">
                    <i class="bi bi-people me-2"></i> Gerenciar usuários <span class="badge bg-secondary float-end">{{ $totalUsuarios }}</span>
                </a>
                <a href="{{ route('admin.financeiro.index') }}" class="btn btn-outline-secondary text-start">
                    <i class="bi bi-cash me-2"></i> Aprovar saques <span class="badge bg-warning text-dark float-end">{{ $saquesPendentes }}</span>
                </a>
                <a href="{{ route('admin.processamento.index') }}" class="btn btn-outline-secondary text-start">
                    <i class="bi bi-cpu me-2"></i> Fila de processamento
                </a>
                <a href="{{ route('admin.leads.index') }}" class="btn btn-outline-secondary text-start">
                    <i class="bi bi-envelope me-2"></i> Ver leads capturados
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
