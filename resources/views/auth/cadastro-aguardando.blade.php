@extends('theme::layouts.public')

@section('titulo', 'Cadastro em análise — ' . config('app.name'))

@section('conteudo')
<div class="d-flex align-items-center justify-content-center min-vh-100 p-3"
     style="background: linear-gradient(135deg, #1a1f36 0%, #2a2f4a 100%);">
    <div class="card shadow-lg border-0" style="max-width: 520px; border-radius: 1rem;">
        <div class="card-body p-4 p-md-5 text-center">
            <div class="d-inline-flex align-items-center justify-content-center mb-3"
                 style="width:72px;height:72px;border-radius:50%;background:#fef3c7;color:#d97706;font-size:2rem;">
                <i class="bi bi-hourglass-split"></i>
            </div>
            <h2 class="fw-bold mb-2">Cadastro em análise</h2>

            <p class="text-muted mb-3">
                Recebemos seu cadastro
                @if(session('email'))
                    <br><strong class="text-dark">{{ session('email') }}</strong>
                @endif
            </p>

            <div class="alert alert-warning small mb-4">
                <i class="bi bi-info-circle me-1"></i>
                Sua conta precisa ser aprovada por um administrador antes de ser ativada.
                Você receberá um e-mail assim que estiver liberado.
            </div>

            <div class="d-flex gap-2 justify-content-center">
                <a href="{{ url('/') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Voltar ao início
                </a>
                <a href="{{ route('login') }}" class="btn btn-dark-panda">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Já fui aprovado
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
