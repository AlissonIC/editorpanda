@extends('theme::layouts.admin')

@section('titulo', 'Leads')

@section('conteudo')
<x-theme::page-header titulo="Leads capturados" subtitulo="Contatos deixados no formulário público ({{ $total }} no total)" />

<div class="panda-card">
    <div class="table-responsive">
        <table id="tbl-leads" class="table table-hover align-middle w-100">
            <thead><tr>
                <th>#</th><th>E-mail</th><th>WhatsApp</th><th>Origem</th><th>IP</th><th>Data</th>
            </tr></thead>
        </table>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/admin/leads.js')
@endpush
