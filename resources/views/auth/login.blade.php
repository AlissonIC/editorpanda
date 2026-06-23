@extends('theme::layouts.public')

@section('titulo', 'Entrar · ' . config('app.name'))

@section('conteudo')
<div class="d-flex align-items-center justify-content-center" style="min-height: 100vh; background: linear-gradient(135deg, #1a1f36 0%, #2a2f4a 100%);">
    <div class="card shadow-lg border-0" style="max-width: 420px; width: 92%;">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <div style="font-size: 2.5rem;">🐼</div>
                <h2 class="h4 mb-1 fw-bold">{{ config('app.name') }}</h2>
                <p class="text-muted small mb-0">Entre na sua conta</p>
            </div>

            @if(session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('login') }}" novalidate>
                @csrf
                <div class="mb-3">
                    <label class="form-label small">E-mail</label>
                    <input type="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" required autofocus>
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label small">Senha</label>
                    <input type="password" name="password" class="form-control @error('password') is-invalid @enderror" required>
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-check mb-4">
                    <input type="checkbox" name="remember" id="remember" class="form-check-input">
                    <label class="form-check-label small" for="remember">Lembrar de mim</label>
                </div>
                <button type="submit" class="btn btn-dark-panda w-100 mb-3">Entrar</button>
                <div class="d-flex justify-content-between small">
                    <a href="{{ route('password.request') }}" class="text-muted">Esqueci a senha</a>
                    <a href="{{ route('register') }}" class="text-muted">Criar conta</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
