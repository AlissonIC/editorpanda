@extends('theme::layouts.public')

@section('titulo', config('app.name') . ' — Em melhorias')

@section('conteudo')
<div class="d-flex flex-column min-vh-100" style="background: linear-gradient(135deg, #1a1f36 0%, #2a2f4a 100%); color: #fff;">
    <header class="container py-4">
        <div class="d-flex align-items-center gap-2">
            <span style="font-size: 1.6rem;">🐼</span>
            <span class="fs-5 fw-bold">{{ config('app.name') }}</span>
        </div>
    </header>

    <main class="container flex-grow-1 d-flex align-items-center py-5">
        <div class="row align-items-center g-5 w-100">
            <div class="col-lg-7">
                <span class="badge bg-light text-dark mb-3 px-3 py-2">✨ Estamos em melhorias</span>
                <h1 class="display-4 fw-bold mb-3">A nova plataforma do Panda está chegando</h1>
                <p class="lead text-white-50 mb-0">
                    Câmera lenta automática, marca d'água, rotação inteligente, álbuns organizados e vendas diretas.
                    Deixe seu e-mail ou WhatsApp e te avisamos no lançamento.
                </p>
            </div>
            <div class="col-lg-5">
                <div class="card border-0 shadow-lg" style="border-radius: 1rem;">
                    <div class="card-body p-4 p-md-5 text-dark">
                        <h3 class="h5 fw-bold mb-1">Avise-me</h3>
                        <p class="small text-muted mb-4">Sem spam, prometemos.</p>

                        <form id="lead-form" novalidate>
                            @csrf
                            <div class="row g-3">
                                <div class="col-sm-7 mb-3">
                                    <label class="form-label small">E-mail</label>
                                    <input type="email" name="email" class="form-control" placeholder="seu@email.com">
                                    <div class="invalid-feedback" data-field="email"></div>
                                </div>
                                <div class="col-sm-5 mb-4">
                                    <label class="form-label small">WhatsApp</label>
                                    <input type="text" name="whatsapp" class="form-control" placeholder="(11) 99999-9999">
                                    <div class="invalid-feedback" data-field="whatsapp"></div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-dark-panda w-100">
                                <span class="label">Quero ser avisado</span>
                                <span class="spinner-border spinner-border-sm ms-2 d-none" role="status"></span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer class="container py-4 text-white-50 small">
        © {{ date('Y') }} {{ config('app.name') }}. Todos os direitos reservados.
    </footer>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/public/home.js')
@endpush
