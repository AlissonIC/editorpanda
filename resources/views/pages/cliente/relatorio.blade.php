@extends('theme::layouts.cliente')

@section('titulo', 'Relatório')

@section('conteudo')
<x-theme::page-header titulo="Relatório" subtitulo="Análise das suas vendas" />

<div class="row g-4">
    <div class="col-lg-7">
        <div class="panda-card h-100">
            <h3 class="h6 fw-bold mb-3">Vendas por mês (últimos 12 meses)</h3>
            <canvas id="chart-vendas" height="160"></canvas>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="panda-card h-100">
            <h3 class="h6 fw-bold mb-3">Top 5 álbuns</h3>
            <canvas id="chart-top" height="220"></canvas>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/cliente/relatorio.js')
@endpush
