@extends('theme::layouts.painel')

@section('titulo', 'Processamento')

@section('conteudo')
<x-theme::page-header titulo="Fila de Processamento" subtitulo="Acompanhe os vídeos em fila e seu status" />

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><x-theme::stat-card label="Pendentes"   value="{{ $contadores['pendente'] }}"    icon="bi-hourglass"       color="warning" /></div>
    <div class="col-6 col-md-3"><x-theme::stat-card label="Processando" value="{{ $contadores['processando'] }}" icon="bi-cpu"             color="info"    /></div>
    <div class="col-6 col-md-3"><x-theme::stat-card label="Concluídos"  value="{{ $contadores['concluido'] }}"   icon="bi-check-circle"    color="success" /></div>
    <div class="col-6 col-md-3"><x-theme::stat-card label="Falharam"    value="{{ $contadores['falhou'] }}"      icon="bi-x-octagon"       color="warning" /></div>
</div>

<div class="panda-card">
    <div class="table-responsive">
        <table id="tbl-processamento" class="table table-hover align-middle w-100">
            <thead><tr>
                <th>Vídeo</th><th>Álbum</th><th>Cliente</th><th>Tamanho</th><th>Status</th><th>Enviado em</th><th class="text-end">Ações</th>
            </tr></thead>
        </table>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/painel/processamento.js')
@endpush
