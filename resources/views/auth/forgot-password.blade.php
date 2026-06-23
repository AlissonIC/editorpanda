@extends('theme::layouts.public')

@section('titulo', 'Recuperar senha · ' . config('app.name'))

@section('conteudo')
<div class="d-flex align-items-center justify-content-center" style="min-height: 100vh; background: linear-gradient(135deg, #1a1f36 0%, #2a2f4a 100%);">
    <div class="card shadow-lg border-0" style="max-width: 420px; width: 92%;">
        <div class="card-body p-4 p-md-5">
            <h2 class="h4 mb-3 fw-bold text-center">Esqueci minha senha</h2>
            <p class="text-muted small text-center mb-4">Informe seu e-mail para receber um link de redefinição.</p>

            @if(session('status'))
                <div class="alert alert-success small">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('password.email') }}">
                @csrf
                <div class="mb-4">
                    <label class="form-label small">E-mail</label>
                    <input type="email" name="email" value="{{ old('email') }}" class="form-control @error('email') is-invalid @enderror" required autofocus>
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <button type="submit" class="btn btn-dark-panda w-100 mb-3">Enviar link</button>
                <div class="text-center small">
                    <a href="{{ route('login') }}" class="text-muted">Voltar para login</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
