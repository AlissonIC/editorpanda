@extends('theme::layouts.cliente')

@section('titulo', 'Pedidos')

@section('conteudo')
<x-theme::page-header titulo="Meus Pedidos" subtitulo="Histórico de vendas dos seus álbuns" />

<div class="panda-card">
    <div class="table-responsive">
        <table id="tbl-pedidos" class="table table-hover align-middle w-100">
            <thead><tr>
                <th>#</th><th>Álbum</th><th>Comprador</th><th>E-mail</th><th>Total</th><th>Status</th><th>Data</th>
            </tr></thead>
        </table>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/cliente/pedidos.js')
@endpush
