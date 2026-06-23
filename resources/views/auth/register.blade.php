@extends('theme::layouts.public')

@section('titulo', 'Criar conta · ' . config('app.name'))

@section('conteudo')
<div class="d-flex align-items-center justify-content-center" style="min-height: 100vh; background: linear-gradient(135deg, #1a1f36 0%, #2a2f4a 100%);">
    <div class="card shadow-lg border-0 my-4" style="max-width: 560px; width: 92%;">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <div style="font-size: 2.5rem;">🐼</div>
                <h2 class="h4 mb-1 fw-bold">Criar conta</h2>
                <p class="text-muted small mb-0">Comece a vender suas mídias</p>
            </div>

            <form method="POST" action="{{ route('register') }}" novalidate>
                @csrf
                <div class="mb-3">
                    <label class="form-label small">Nome completo</label>
                    <input type="text" name="nome" value="{{ old('nome') }}" class="form-control @error('nome') is-invalid @enderror" required autofocus>
                    @error('nome')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="row g-3">
                    <div class="col-md-7 mb-3">
                        <label class="form-label small">E-mail</label>
                        <input type="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" required>
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-5 mb-3">
                        <label class="form-label small">WhatsApp</label>
                        <input type="text" name="whatsapp" value="{{ old('whatsapp') }}" class="form-control @error('whatsapp') is-invalid @enderror" placeholder="(11) 99999-9999">
                        @error('whatsapp')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6 mb-3">
                        <label class="form-label small">Senha</label>
                        <input type="password" name="password" class="form-control @error('password') is-invalid @enderror" required>
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6 mb-4">
                        <label class="form-label small">Confirmar senha</label>
                        <input type="password" name="password_confirmation" class="form-control" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-dark-panda w-100 mb-3">Criar conta</button>
                <div class="text-center small">
                    Já tem conta? <a href="{{ route('login') }}" class="text-muted">Entrar</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
