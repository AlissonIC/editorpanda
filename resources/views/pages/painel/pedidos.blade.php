@php $isAdmin = auth()->user()->isAdmin(); @endphp
@extends('theme::layouts.painel')

@section('titulo', 'Pedidos')

@section('conteudo')
<x-theme::page-header
    titulo="{{ $isAdmin ? 'Pedidos' : 'Meus Pedidos' }}"
    subtitulo="{{ $isAdmin ? 'Pedidos de compradores em todos os álbuns' : 'Histórico de vendas dos seus álbuns' }}"
/>

<div class="panda-card">
    <div class="table-responsive">
        <table id="tbl-pedidos" class="table table-hover align-middle w-100">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Álbum</th>
                    @if($isAdmin)<th>Cliente dono</th>@endif
                    <th>Comprador</th>
                    <th>E-mail</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Data</th>
                </tr>
            </thead>
        </table>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/painel/pedidos.js')
@endpush
