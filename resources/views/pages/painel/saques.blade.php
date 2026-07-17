@extends('theme::layouts.painel')

@section('titulo', 'Saques')

@section('conteudo')
<x-theme::page-header
    titulo="Meus saques"
    subtitulo="Solicite retirada do saldo disponível"
>
    <button type="button" class="btn btn-dark-panda" data-bs-toggle="modal" data-bs-target="#modalSaque">
        <i class="bi bi-cash-coin me-1"></i> Solicitar saque
    </button>
</x-theme::page-header>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <x-theme::stat-card
            label="Saldo disponível"
            value="R$ {{ number_format($saldo, 2, ',', '.') }}"
            icon="bi-wallet2"
            color="success"
        />
    </div>
</div>

<div class="panda-card">
    <div class="table-responsive">
        <table id="tbl-saques" class="table table-hover align-middle w-100">
            <thead><tr>
                <th>Valor</th><th>Status</th><th>Solicitado</th><th>Pago</th><th>Observação</th>
            </tr></thead>
        </table>
    </div>
</div>

<div class="modal fade" id="modalSaque" tabindex="-1">
    <div class="modal-dialog">
        <form id="form-saque" class="modal-content" novalidate>
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Solicitar saque</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">Valor mínimo R$ 20,00. Saldo atual:
                    <strong>R$ {{ number_format($saldo, 2, ',', '.') }}</strong>.
                </p>

                <div class="mb-3">
                    <label class="form-label small">Valor</label>
                    <input type="text" name="valor" data-mask="money" class="form-control" required>
                    <div class="invalid-feedback" data-field="valor"></div>
                </div>

                <div class="mb-3">
                    <label class="form-label small">Tipo</label>
                    <select name="dados_bancarios[tipo]" class="form-select" id="saque-tipo">
                        <option value="pix">PIX</option>
                        <option value="ted">TED</option>
                    </select>
                </div>

                <div class="mb-3" id="saque-pix-wrap">
                    <label class="form-label small">Chave PIX</label>
                    <input type="text" name="dados_bancarios[chave]" class="form-control">
                </div>

                <div class="d-none" id="saque-ted-wrap">
                    <div class="row g-2">
                        <div class="col-md-6 mb-3">
                            <label class="form-label small">Banco</label>
                            <input type="text" name="dados_bancarios[banco]" class="form-control">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label small">Agência</label>
                            <input type="text" name="dados_bancarios[agencia]" class="form-control">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label small">Conta</label>
                            <input type="text" name="dados_bancarios[conta]" class="form-control">
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small">Titular</label>
                    <input type="text" name="dados_bancarios[titular]" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label small">Observação</label>
                    <textarea name="observacao" class="form-control" rows="2" maxlength="500"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-dark-panda">Enviar solicitação</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/painel/saques.js')
@endpush
