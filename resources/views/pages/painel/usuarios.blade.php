@extends('theme::layouts.painel')

@section('titulo', 'Usuários')

@section('conteudo')
<x-theme::page-header titulo="Gerenciar Usuários" subtitulo="Admins e clientes cadastrados na plataforma">
    <button type="button" class="btn btn-dark-panda" data-bs-toggle="modal" data-bs-target="#modalUsuario" id="btn-novo">
        <i class="bi bi-plus-lg me-1"></i> Novo Usuário
    </button>
</x-theme::page-header>

<div class="panda-card">
    <div class="table-responsive">
        <table id="tbl-usuarios" class="table table-hover align-middle w-100">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>E-mail</th>
                    <th>WhatsApp</th>
                    <th>Perfil</th>
                    <th>Saldo</th>
                    <th>Criado em</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
        </table>
    </div>
</div>

<div class="modal fade" id="modalUsuario" tabindex="-1">
    <div class="modal-dialog">
        <form id="form-usuario" class="modal-content" novalidate>
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Novo Usuário</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id">
                <div class="row g-3">
                    <div class="col-md-7 mb-3">
                        <label class="form-label small">Nome</label>
                        <input type="text" name="nome" class="form-control" required>
                        <div class="invalid-feedback" data-field="nome"></div>
                    </div>
                    <div class="col-md-5 mb-3">
                        <label class="form-label small">Perfil</label>
                        <select name="role" class="form-select">
                            <option value="cliente">Cliente</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6 mb-3">
                        <label class="form-label small">E-mail</label>
                        <input type="email" name="email" class="form-control" required>
                        <div class="invalid-feedback" data-field="email"></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label small">WhatsApp</label>
                        <input type="text" name="whatsapp" class="form-control">
                        <div class="invalid-feedback" data-field="whatsapp"></div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Senha <small class="text-muted">(deixe vazio ao editar)</small></label>
                    <input type="password" name="password" class="form-control">
                    <div class="invalid-feedback" data-field="password"></div>
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
    @vite('resources/js/pages/painel/usuarios.js')
@endpush
