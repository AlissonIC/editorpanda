@extends('theme::layouts.admin')

@section('titulo', 'Pedidos')

@section('conteudo')
<x-theme::page-header titulo="Pedidos" subtitulo="Pedidos de compradores em todos os álbuns" />

<div class="panda-card">
    <div class="table-responsive">
        <table id="tbl-pedidos" class="table table-hover align-middle w-100">
            <thead><tr>
                <th>#</th><th>Álbum</th><th>Cliente dono</th><th>Comprador</th><th>Total</th><th>Status</th><th>Criado em</th>
            </tr></thead>
        </table>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/admin/pedidos.js')
@endpush
