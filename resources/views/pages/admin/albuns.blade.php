@extends('theme::layouts.admin')

@section('titulo', 'Álbuns')

@section('conteudo')
<x-theme::page-header titulo="Gerenciar Álbuns" subtitulo="Todos os álbuns publicados pelos clientes" />

<div class="panda-card">
    <div class="table-responsive">
        <table id="tbl-albuns" class="table table-hover align-middle w-100">
            <thead><tr>
                <th>Álbum</th><th>Cliente</th><th>Evento</th><th>Vídeos</th><th>Status</th><th>Criado em</th><th class="text-end">Ações</th>
            </tr></thead>
        </table>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/admin/albuns.js')
@endpush
