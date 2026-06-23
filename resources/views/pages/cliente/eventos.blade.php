@extends('theme::layouts.cliente')

@section('titulo', 'Eventos')

@section('conteudo')
<x-theme::page-header titulo="Meus Eventos" subtitulo="Organize seus eventos para agrupar álbuns">
    <button type="button" class="btn btn-dark-panda" data-bs-toggle="modal" data-bs-target="#modalEvento" id="btn-novo">
        <i class="bi bi-plus-lg me-1"></i> Novo Evento
    </button>
</x-theme::page-header>

<div class="panda-card">
    <div class="table-responsive">
        <table id="tbl-eventos" class="table table-hover align-middle w-100">
            <thead><tr>
                <th>Evento</th><th>Localização</th><th>Data</th><th>Status</th><th>Álbuns</th><th class="text-end">Ações</th>
            </tr></thead>
        </table>
    </div>
</div>

<div class="modal fade" id="modalEvento" tabindex="-1">
    <div class="modal-dialog">
        <form id="form-evento" class="modal-content" novalidate>
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Novo Evento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id">
                <div class="mb-3">
                    <label class="form-label small">Nome do evento</label>
                    <input type="text" name="nome" class="form-control" required>
                    <div class="invalid-feedback" data-field="nome"></div>
                </div>
                <div class="row g-3">
                    <div class="col-md-7 mb-3">
                        <label class="form-label small">Cidade</label>
                        <input type="text" name="localizacao_cidade" class="form-control">
                    </div>
                    <div class="col-md-5 mb-3">
                        <label class="form-label small">Estado</label>
                        <input type="text" name="localizacao_estado" class="form-control">
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6 mb-3">
                        <label class="form-label small">Data</label>
                        <input type="date" name="data" class="form-control">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label small">Status</label>
                        <select name="status" class="form-select">
                            <option value="ativo">Ativo</option>
                            <option value="inativo">Inativo</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-dark-panda">Salvar</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/cliente/eventos.js')
@endpush
