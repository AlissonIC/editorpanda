@extends('theme::layouts.admin')

@section('titulo', 'Eventos')

@section('conteudo')
<x-theme::page-header titulo="Gerenciar Eventos" subtitulo="Eventos cadastrados pelos clientes" />

<div class="panda-card">
    <div class="table-responsive">
        <table id="tbl-eventos" class="table table-hover align-middle w-100">
            <thead><tr>
                <th>Evento</th><th>Cliente</th><th>Localização</th><th>Data</th><th>Status</th><th>Álbuns</th><th class="text-end">Ações</th>
            </tr></thead>
        </table>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/admin/eventos.js')
@endpush
