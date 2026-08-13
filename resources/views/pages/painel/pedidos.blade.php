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
                    <th>Álbum</th>
                    @if($isAdmin)<th>Cliente dono</th>@endif
                    <th>Comprador</th>
                    <th>Total</th>
                    <th>Pagamento</th>
                    <th>Status</th>
                    <th>Data</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
        </table>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/painel/pedidos.js')
@endpush
