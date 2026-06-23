@extends('theme::layouts.painel')

@section('titulo', 'Financeiro')

@section('conteudo')
<x-theme::page-header titulo="Financeiro" subtitulo="Vendas, saques e movimentação financeira" />

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <x-theme::stat-card label="Total Vendido (pago)" value="R$ {{ number_format($totalPago, 2, ',', '.') }}" icon="bi-cash-stack" color="success" />
    </div>
    <div class="col-md-4">
        <x-theme::stat-card label="Saques pagos" value="R$ {{ number_format($totalSaquesPagos, 2, ',', '.') }}" icon="bi-bank" color="info" />
    </div>
    <div class="col-md-4">
        <x-theme::stat-card label="Saques pendentes" value="R$ {{ number_format($totalSaquesPendentes, 2, ',', '.') }}" icon="bi-hourglass-split" color="warning" />
    </div>
</div>

<ul class="nav nav-tabs mb-3" id="abas-financeiro">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#aba-vendas">Vendas</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#aba-saques">Saques</a></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="aba-vendas">
        <div class="panda-card">
            <div class="table-responsive">
                <table id="tbl-vendas" class="table table-hover align-middle w-100">
                    <thead><tr>
                        <th>#</th><th>Álbum</th><th>Cliente</th><th>Comprador</th><th>Total</th><th>Status</th><th>Data</th>
                    </tr></thead>
                </table>
            </div>
        </div>
    </div>
    <div class="tab-pane fade" id="aba-saques">
        <div class="panda-card">
            <div class="table-responsive">
                <table id="tbl-saques" class="table table-hover align-middle w-100">
                    <thead><tr>
                        <th>#</th><th>Cliente</th><th>Valor</th><th>Status</th><th>Solicitado em</th><th class="text-end">Ações</th>
                    </tr></thead>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/painel/financeiro.js')
@endpush
