@extends('theme::layouts.public')

@section('titulo', 'Nova senha · ' . config('app.name'))

@section('conteudo')
<div class="d-flex align-items-center justify-content-center" style="min-height: 100vh; background: linear-gradient(135deg, #1a1f36 0%, #2a2f4a 100%);">
    <div class="card shadow-lg border-0" style="max-width: 520px; width: 92%;">
        <div class="card-body p-4 p-md-5">
            <h2 class="h4 mb-3 fw-bold text-center">Nova senha</h2>
            <form method="POST" action="{{ route('password.store') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $request->route('token') }}">
                <div class="mb-3">
                    <label class="form-label small">E-mail</label>
                    <input type="email" name="email" value="{{ old('email', $request->email) }}" class="form-control @error('email') is-invalid @enderror" required>
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
                <button type="submit" class="btn btn-dark-panda w-100">Salvar nova senha</button>
            </form>
        </div>
    </div>
</div>
@endsection
