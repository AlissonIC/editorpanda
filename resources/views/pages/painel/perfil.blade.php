@extends('theme::layouts.painel')

@section('titulo', 'Meu Perfil')

@section('conteudo')
<x-theme::page-header titulo="Meu Perfil" subtitulo="Edite seus dados, foto e senha" />

<div class="row g-4">
    {{-- Foto de perfil --}}
    <div class="col-lg-4">
        <div class="panda-card text-center">
            <h3 class="h6 fw-bold mb-3 text-start">Foto de perfil</h3>

            <div id="avatar-wrap" class="d-inline-flex align-items-center justify-content-center mb-3"
                 style="width: 140px; height: 140px; border-radius: 50%; background: #ebe9fd; color: #7367f0; font-size: 2.6rem; font-weight: 700; overflow: hidden; border: 4px solid #fff; box-shadow: 0 0 0 1px #eaecf4;">
                @if($user->foto_url)
                    <img id="avatar-img" src="{{ $user->foto_url }}?v={{ time() }}" alt="" style="width:100%;height:100%;object-fit:cover;">
                @else
                    <span id="avatar-iniciais">{{ $user->iniciais }}</span>
                @endif
            </div>

            <p class="text-muted small mb-3">JPG, PNG ou WEBP · máx 2MB</p>

            <form id="form-foto" enctype="multipart/form-data" class="d-flex gap-2 justify-content-center">
                @csrf
                <label class="btn btn-dark-panda mb-0">
                    <i class="bi bi-upload me-1"></i> Alterar foto
                    <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" class="d-none" id="input-foto">
                </label>
                <button type="button" id="btn-remover-foto" class="btn btn-outline-danger {{ $user->foto_perfil ? '' : 'd-none' }}">
                    <i class="bi bi-trash"></i>
                </button>
            </form>

            <div class="progress mt-3 d-none" style="height: 6px;">
                <div class="progress-bar" role="progressbar" style="width: 0%"></div>
            </div>
        </div>

        <div class="panda-card mt-3 small">
            <div class="text-muted">Perfil</div>
            <div class="fw-semibold mb-2">{{ ucfirst($user->role) }}</div>
            <div class="text-muted">Cadastrado em</div>
            <div class="fw-semibold">{{ $user->created_at?->format('d/m/Y') }}</div>
        </div>
    </div>

    {{-- Dados pessoais --}}
    <div class="col-lg-8">
        <div class="panda-card mb-4">
            <h3 class="h6 fw-bold mb-3">Dados pessoais</h3>

            <form id="form-dados" novalidate>
                @csrf
                <div class="mb-3">
                    <label class="form-label small">Nome completo</label>
                    <input type="text" name="nome" value="{{ $user->nome }}" class="form-control" required>
                    <div class="invalid-feedback" data-field="nome"></div>
                </div>
                <div class="row g-3">
                    <div class="col-md-7 mb-3">
                        <label class="form-label small">E-mail</label>
                        <input type="email" name="email" value="{{ $user->email }}" class="form-control" required>
                        <div class="invalid-feedback" data-field="email"></div>
                    </div>
                    <div class="col-md-5 mb-3">
                        <label class="form-label small">WhatsApp</label>
                        <input type="text" name="whatsapp" value="{{ $user->whatsapp }}" class="form-control" placeholder="(11) 99999-9999">
                        <div class="invalid-feedback" data-field="whatsapp"></div>
                    </div>
                </div>
                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-dark-panda">Salvar alterações</button>
                </div>
            </form>
        </div>

        {{-- Endereço --}}
        <div class="panda-card mb-4">
            <h3 class="h6 fw-bold mb-3">Endereço</h3>

            <form id="form-endereco" novalidate>
                @csrf
                {{-- Linha 1: CEP · Estado · Cidade --}}
                <div class="row g-3">
                    <div class="col-md-3 mb-3">
                        <label class="form-label small">CEP</label>
                        <input type="text" name="cep" value="{{ $user->cep }}" class="form-control" placeholder="00000-000">
                        <div class="invalid-feedback" data-field="cep"></div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label small">Estado</label>
                        @include('theme::partials.select-estado', ['name' => 'estado', 'selected' => $user->estado])
                        <div class="invalid-feedback" data-field="estado"></div>
                    </div>
                    <div class="col-md-5 mb-3">
                        <label class="form-label small">Cidade</label>
                        <input type="text" name="cidade" value="{{ $user->cidade }}" class="form-control" autocomplete="address-level2">
                        <div class="invalid-feedback" data-field="cidade"></div>
                    </div>
                </div>

                {{-- Linha 2: Bairro · Logradouro · Nº --}}
                <div class="row g-3">
                    <div class="col-md-4 mb-3">
                        <label class="form-label small">Bairro</label>
                        <input type="text" name="bairro" value="{{ $user->bairro }}" class="form-control">
                        <div class="invalid-feedback" data-field="bairro"></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label small">Endereço</label>
                        <input type="text" name="logradouro" value="{{ $user->logradouro }}" class="form-control" placeholder="Rua, avenida…" autocomplete="street-address">
                        <div class="invalid-feedback" data-field="logradouro"></div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label small">Nº</label>
                        <input type="text" name="numero" value="{{ $user->numero }}" class="form-control">
                        <div class="invalid-feedback" data-field="numero"></div>
                    </div>
                </div>

                {{-- Linha 3: Complemento --}}
                <div class="mb-3">
                    <label class="form-label small">Complemento</label>
                    <input type="text" name="complemento" value="{{ $user->complemento }}" class="form-control" placeholder="Apto, sala, bloco…">
                    <div class="invalid-feedback" data-field="complemento"></div>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-dark-panda">Salvar endereço</button>
                </div>
            </form>
        </div>

        {{-- Senha --}}
        <div class="panda-card">
            <h3 class="h6 fw-bold mb-3">Trocar senha</h3>

            <form id="form-senha" novalidate>
                @csrf
                <div class="mb-3">
                    <label class="form-label small">Senha atual</label>
                    <input type="password" name="senha_atual" class="form-control" required autocomplete="current-password">
                    <div class="invalid-feedback" data-field="senha_atual"></div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6 mb-3">
                        <label class="form-label small">Nova senha</label>
                        <input type="password" name="password" class="form-control" required autocomplete="new-password">
                        <div class="invalid-feedback" data-field="password"></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label small">Confirmar nova senha</label>
                        <input type="password" name="password_confirmation" class="form-control" required autocomplete="new-password">
                    </div>
                </div>
                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-dark-panda">Atualizar senha</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/painel/perfil.js')
@endpush
