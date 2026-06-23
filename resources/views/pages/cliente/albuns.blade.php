@extends('theme::layouts.cliente')

@section('titulo', 'Álbuns')

@section('conteudo')
<x-theme::page-header titulo="Meus Álbuns" subtitulo="Crie álbuns e envie vídeos para vender">
    <button type="button" class="btn btn-dark-panda" data-bs-toggle="modal" data-bs-target="#modalAlbum" id="btn-novo">
        <i class="bi bi-plus-lg me-1"></i> Novo Álbum
    </button>
</x-theme::page-header>

<div class="panda-card">
    <div class="table-responsive">
        <table id="tbl-albuns" class="table table-hover align-middle w-100">
            <thead><tr>
                <th>Álbum</th><th>Evento</th><th>Preço</th><th>Vídeos</th><th>Status</th><th>Criado em</th><th class="text-end">Ações</th>
            </tr></thead>
        </table>
    </div>
</div>

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
                <div class="mb-3">
                    <label class="form-label small">Evento</label>
                    <select name="evento_id" class="form-select" required>
                        <option value="">Selecione...</option>
                        @foreach($eventos as $e)
                            <option value="{{ $e->id }}">{{ $e->nome }}</option>
                        @endforeach
                    </select>
                    <div class="invalid-feedback" data-field="evento_id"></div>
                </div>
                <div class="row g-3">
                    <div class="col-md-7 mb-3">
                        <label class="form-label small">Nome</label>
                        <input type="text" name="nome" class="form-control" required>
                        <div class="invalid-feedback" data-field="nome"></div>
                    </div>
                    <div class="col-md-5 mb-3">
                        <label class="form-label small">Subtítulo</label>
                        <input type="text" name="subtitulo" class="form-control">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Descrição</label>
                    <textarea name="descricao" class="form-control" rows="2"></textarea>
                </div>
                <div class="row g-3">
                    <div class="col-md-6 mb-3">
                        <label class="form-label small">Preço (R$)</label>
                        <input type="number" name="preco" step="0.01" min="0" value="0.00" class="form-control" required>
                    </div>
                    <div class="col-md-6 mb-3">
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

<div class="modal fade" id="modalUpload" tabindex="-1">
    <div class="modal-dialog">
        <form id="form-upload" class="modal-content" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="album_id">
            <div class="modal-header">
                <h5 class="modal-title">Enviar vídeo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">Formatos: MP4, MOV, MKV, WEBM. Tamanho máx: 500MB.</p>
                <input type="file" name="arquivo" accept="video/*" class="form-control" required>
                <div class="invalid-feedback d-block small mt-2" data-field="arquivo"></div>
                <div class="progress mt-3 d-none" style="height: 8px;">
                    <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-dark-panda">Enviar para processamento</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/cliente/albuns.js')
@endpush
