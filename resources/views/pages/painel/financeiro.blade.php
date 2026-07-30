@extends('theme::layouts.painel')

@section('titulo', 'Financeiro')

@section('conteudo')
<x-theme::page-header titulo="Financeiro" subtitulo="Vendas, saques, despesas" />

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <x-theme::stat-card label="Total Vendido (pago)" value="R$ {{ number_format($totalPago, 2, ',', '.') }}" icon="bi-cash-stack" color="success" />
    </div>
    <div class="col-md-3">
        <x-theme::stat-card label="Saques pagos" value="R$ {{ number_format($totalSaquesPagos, 2, ',', '.') }}" icon="bi-bank" color="info" />
    </div>
    <div class="col-md-3">
        <x-theme::stat-card label="Gastos do mês" value="R$ {{ number_format($gastosMes, 2, ',', '.') }}" icon="bi-receipt-cutoff" color="warning" />
    </div>
    <div class="col-md-3">
        <x-theme::stat-card label="Recorrentes mensalizados" value="R$ {{ number_format($gastosRecorrentesMensalizados, 2, ',', '.') }}" icon="bi-arrow-repeat" color="secondary" />
    </div>
</div>

<ul class="nav nav-tabs mb-3" id="abas-financeiro">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#aba-vendas">Vendas</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#aba-saques">Saques</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#aba-gastos">Gastos</a></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="aba-vendas">
        <div class="panda-card">
            <div class="table-responsive">
                <table id="tbl-vendas" class="table table-hover align-middle w-100">
                    <thead><tr>
                        <th>#</th><th>Álbum</th><th>Cliente</th><th>Comprador</th><th>Total</th><th>Status</th><th>Data</th>
                    </tr></thead>
                </table>
            </div>
        </div>
    </div>
    <div class="tab-pane fade" id="aba-saques">
        <div class="panda-card">
            <div class="table-responsive">
                <table id="tbl-saques" class="table table-hover align-middle w-100">
                    <thead><tr>
                        <th>#</th><th>Cliente</th><th>Valor</th><th>Status</th><th>Solicitado em</th><th class="text-end">Ações</th>
                    </tr></thead>
                </table>
            </div>
        </div>
    </div>
    <div class="tab-pane fade" id="aba-gastos">
        <div class="panda-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0">Despesas cadastradas</h6>
                <button type="button" class="btn btn-dark-panda btn-sm" data-bs-toggle="modal" data-bs-target="#modal-despesa" id="btn-nova-despesa">
                    <i class="bi bi-plus-lg me-1"></i> Nova despesa
                </button>
            </div>
            <div class="table-responsive">
                <table id="tbl-despesas" class="table table-hover align-middle w-100">
                    <thead><tr>
                        <th>Descrição</th>
                        <th>Categoria</th>
                        <th>Valor</th>
                        <th>Data</th>
                        <th>Tipo</th>
                        <th class="text-end">Ações</th>
                    </tr></thead>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Modal cadastrar/editar despesa --}}
<div class="modal fade" id="modal-despesa" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form id="form-despesa" class="modal-content" novalidate>
            @csrf
            <input type="hidden" name="id" id="despesa-id">
            <div class="modal-header">
                <h5 class="modal-title" id="despesa-modal-title">Nova despesa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small">Descrição</label>
                    <input type="text" name="descricao" class="form-control" required maxlength="255">
                    <div class="invalid-feedback" data-field="descricao"></div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-8">
                        <label class="form-label small">Valor (R$)</label>
                        <input type="number" name="valor" class="form-control" required min="0.01" step="0.01">
                        <div class="invalid-feedback" data-field="valor"></div>
                    </div>
                    <div class="col-4">
                        <label class="form-label small">Data</label>
                        <input type="date" name="data_gasto" class="form-control" required value="{{ now()->format('Y-m-d') }}">
                        <div class="invalid-feedback" data-field="data_gasto"></div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Categoria <span class="text-muted">(opcional)</span></label>
                    <input type="text" name="categoria" class="form-control" maxlength="60" placeholder="Ex: Servidor, Marketing, MP taxa">
                </div>
                <div class="mb-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="recorrente" value="1" id="despesa-recorrente">
                        <label class="form-check-label small" for="despesa-recorrente">
                            <strong>Despesa recorrente</strong>
                        </label>
                    </div>
                    <div class="mt-2" id="despesa-freq-wrap" style="display:none;">
                        <label class="form-label small">Frequência</label>
                        <select name="frequencia" class="form-select">
                            <option value="mensal">Mensal</option>
                            <option value="anual">Anual</option>
                            <option value="semanal">Semanal</option>
                        </select>
                        <small class="text-muted">Sistema converte pra "mensalizado" no painel.</small>
                    </div>
                </div>
                <div class="mb-0">
                    <label class="form-label small">Observação <span class="text-muted">(opcional)</span></label>
                    <textarea name="observacao" class="form-control" rows="2" maxlength="2000"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-dark-panda" id="despesa-submit">Salvar</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
    <script>window.pandaVendedores = @json($vendedores ?? []);</script>
    @vite('resources/js/pages/painel/financeiro.js')
@endpush
