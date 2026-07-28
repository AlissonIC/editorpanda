@extends('theme::layouts.painel')

@section('titulo', 'Cupons de desconto')

@section('conteudo')
<x-theme::page-header
    titulo="Cupons de desconto"
    subtitulo="Cupons válidos apenas em SEUS eventos/álbuns — outros produtores não podem usá-los."
>
    <button type="button" class="btn btn-dark-panda" id="btn-novo-cupom">
        <i class="bi bi-plus-lg me-1"></i> Novo cupom
    </button>
</x-theme::page-header>

<div class="panda-card"
     data-list-url="{{ route('painel.cupons.data') }}"
     data-store-url="{{ route('painel.cupons.store') }}"
     id="cupons-app">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr class="small text-muted text-uppercase">
                    <th>Código</th>
                    <th>Desconto</th>
                    <th>Restrição</th>
                    <th>Usos</th>
                    <th>Expira</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="cupons-tbody">
                <tr><td colspan="7" class="text-muted small text-center py-4">Carregando…</td></tr>
            </tbody>
        </table>
    </div>
</div>

{{-- Modal de criação/edição --}}
<div class="modal fade" id="modal-cupom" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form id="form-cupom" novalidate>
                @csrf
                <input type="hidden" name="_method" value="POST" id="cupom-method">
                <input type="hidden" id="cupom-id">
                <div class="modal-header">
                    <h5 class="modal-title" id="cupom-modal-title">Novo cupom</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small">Código</label>
                            <input type="text" name="codigo" class="form-control text-uppercase" required
                                   placeholder="BLACKFRIDAY" maxlength="60"
                                   pattern="[A-Za-z0-9_\-]+">
                            <small class="text-muted">Letras/números/hífen. Convertido pra MAIÚSCULAS.</small>
                            <div class="invalid-feedback" data-field="codigo"></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Tipo</label>
                            <select name="tipo" class="form-select" id="cupom-tipo">
                                <option value="percentual">% Percentual</option>
                                <option value="fixo">R$ Fixo</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small">Valor <span id="cupom-valor-unit">(%)</span></label>
                            <input type="number" name="valor" class="form-control" required
                                   min="0.01" max="9999.99" step="0.01" placeholder="10">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small">Restringir a evento</label>
                            <select name="restricao_evento_id" class="form-select" id="cupom-evento">
                                <option value="">Qualquer evento meu</option>
                                @foreach(auth()->user()->eventos()->orderBy('nome')->get(['id','nome']) as $ev)
                                    <option value="{{ $ev->id }}">{{ $ev->nome }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small">Restringir a álbum</label>
                            <select name="restricao_album_id" class="form-select" id="cupom-album">
                                <option value="">Qualquer álbum meu</option>
                                @foreach(auth()->user()->eventos()->with('albuns:id,nome,evento_id')->get() as $ev)
                                    @foreach($ev->albuns as $al)
                                        <option value="{{ $al->id }}">{{ $ev->nome }} · {{ $al->nome }}</option>
                                    @endforeach
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small">Limite de usos</label>
                            <input type="number" name="limite_usos" class="form-control" min="1" max="99999" placeholder="Ilimitado">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Expira em</label>
                            <input type="datetime-local" name="expira_em" class="form-control">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="ativo" value="1" id="cupom-ativo" checked>
                                <label class="form-check-label" for="cupom-ativo">Ativo</label>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label small">E-mails permitidos (whitelist)</label>
                            <textarea name="emails_raw" class="form-control" rows="3"
                                      placeholder="cliente1@ex.com&#10;cliente2@ex.com"></textarea>
                            <small class="text-muted">Um por linha. Vazio = todos os e-mails podem usar.</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-dark-panda">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/painel/cupons.js')
@endpush
