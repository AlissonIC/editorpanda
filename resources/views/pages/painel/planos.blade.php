@extends('theme::layouts.painel')

@section('titulo', 'Planos')

@section('conteudo')
<x-theme::page-header titulo="Gerenciar Planos" subtitulo="Planos exibidos na landing e disponíveis para os clientes">
    <button type="button" class="btn btn-dark-panda" data-bs-toggle="modal" data-bs-target="#modalPlano" id="btn-novo">
        <i class="bi bi-plus-lg me-1"></i> Novo Plano
    </button>
</x-theme::page-header>

<div class="panda-card">
    <div class="table-responsive">
        <table id="tbl-planos" class="table table-hover align-middle w-100">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Preço</th>
                    <th>Armazenamento</th>
                    <th>Taxa</th>
                    <th>Popular</th>
                    <th>Status</th>
                    <th>Ordem</th>
                    <th>Usuários</th>
                    <th>Criado em</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
        </table>
    </div>
</div>

<div class="modal fade" id="modalPlano" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="form-plano" class="modal-content" novalidate>
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Novo Plano</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id">

                <div class="row g-3">
                    <div class="col-md-8 mb-3">
                        <label class="form-label small">Nome</label>
                        <input type="text" name="nome" class="form-control" required>
                        <div class="invalid-feedback" data-field="nome"></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label small">Ordem</label>
                        <input type="number" name="ordem" class="form-control" min="0" value="0">
                        <div class="invalid-feedback" data-field="ordem"></div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small">Descrição</label>
                    <input type="text" name="descricao" class="form-control" maxlength="255"
                           placeholder="Ex.: Plano ideal para começar a vender suas mídias.">
                    <div class="invalid-feedback" data-field="descricao"></div>
                </div>

                <div class="row g-3">
                    <div class="col-md-4 mb-3">
                        <label class="form-label small">Preço</label>
                        <input type="text" name="preco" data-mask="money" class="form-control" required>
                        <div class="invalid-feedback" data-field="preco"></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label small">Armazenamento (GB)</label>
                        <input type="number" name="armazenamento_gb" class="form-control" min="1" required>
                        <div class="invalid-feedback" data-field="armazenamento_gb"></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label small">Taxa por venda (%)</label>
                        <input type="number" name="taxa_por_venda" class="form-control" step="0.01" min="0" max="100" value="10.00" required>
                        <div class="invalid-feedback" data-field="taxa_por_venda"></div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6 mb-2">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="popular" value="1" id="popular">
                            <label class="form-check-label" for="popular">Destacar como <strong>Popular</strong></label>
                        </div>
                    </div>
                    <div class="col-md-6 mb-2">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="ativo" value="1" id="ativo" checked>
                            <label class="form-check-label" for="ativo">Ativo (visível na landing)</label>
                        </div>
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
    @vite('resources/js/pages/painel/planos.js')
@endpush
