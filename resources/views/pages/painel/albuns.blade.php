@php $isAdmin = auth()->user()->isAdmin(); @endphp
@extends('theme::layouts.painel')

@section('titulo', 'Álbuns')

@section('conteudo')
<x-theme::page-header
    titulo="{{ $isAdmin ? 'Gerenciar Álbuns' : 'Meus Álbuns' }}"
    subtitulo="{{ $isAdmin ? 'Todos os álbuns publicados pelos clientes' : 'Crie álbuns e envie vídeos para vender' }}"
>
    @unless($isAdmin)
        <button type="button" class="btn btn-dark-panda" data-bs-toggle="modal" data-bs-target="#modalAlbum" id="btn-novo">
            <i class="bi bi-plus-lg me-1"></i> Novo Álbum
        </button>
    @endunless
</x-theme::page-header>

<div class="panda-card">
    <div class="table-responsive">
        <table id="tbl-albuns" class="table table-hover align-middle w-100">
            <thead>
                <tr>
                    <th>Álbum</th>
                    @if($isAdmin)<th>Cliente</th>@endif
                    <th>Evento</th>
                    @unless($isAdmin)<th>Preço</th>@endunless
                    <th>Vídeos</th>
                    <th>Status</th>
                    <th>Criado em</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
        </table>
    </div>
</div>

@unless($isAdmin)
<div class="modal fade" id="modalAlbum" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="form-album" class="modal-content" novalidate>
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Novo Álbum</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id">
                <div class="row g-3">
                    <div class="col-md-5 mb-3">
                        <label class="form-label small">Evento</label>
                        <select name="evento_id" class="form-select" required>
                            <option value="">Selecione...</option>
                            @foreach($eventos as $e)
                                <option value="{{ $e->id }}">{{ $e->nome }}</option>
                            @endforeach
                        </select>
                        <div class="invalid-feedback" data-field="evento_id"></div>
                    </div>
                    <div class="col-md-7 mb-3">
                        <label class="form-label small">Nome</label>
                        <input type="text" name="nome" class="form-control" required>
                        <div class="invalid-feedback" data-field="nome"></div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Subtítulo</label>
                    <input type="text" name="subtitulo" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label small">Descrição</label>
                    <textarea name="descricao" class="form-control" rows="2"></textarea>
                </div>
                <div class="row g-3">
                    <div class="col-md-4 mb-3">
                        <label class="form-label small">Preço fixo do álbum</label>
                        <input type="text" name="preco" data-mask="money" value="0.00" class="form-control" required>
                        <small class="text-muted">Para pacotes; opcional.</small>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label small">Preço por vídeo</label>
                        <input type="text" name="preco_por_video" data-mask="money" class="form-control mb-1" id="album-preco-video">
                        <div class="form-check form-check-sm">
                            <input class="form-check-input" type="checkbox" name="herdar_preco_evento" value="1" id="album-herdar-preco">
                            <label class="form-check-label small text-muted" for="album-herdar-preco">
                                Usar preço do evento (<span id="album-preco-evento-preview">R$ 0,00</span>)
                            </label>
                        </div>
                        <div class="invalid-feedback" data-field="preco_por_video"></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label small">Status</label>
                        <select name="status" class="form-select">
                            <option value="rascunho">Rascunho</option>
                            <option value="publicado">Publicado</option>
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

@endunless
@endsection

@push('scripts')
    <script>window.pandaEventos = @json($eventos ?? []);</script>
    @vite('resources/js/pages/painel/albuns.js')
@endpush
