@extends('theme::layouts.publico-produto')

@section('titulo', 'Acessar minhas compras — ' . config('app.name'))

@section('conteudo')
<section class="container py-5">
    <div class="pv-checkout-card mx-auto" style="max-width: 480px;">
        <h3 class="fw-bold mb-3 text-center">Acessar minhas compras</h3>
        <p class="text-muted text-center small mb-4">
            Digite o e-mail usado na compra. Enviaremos um link mágico para você entrar
            sem precisar de senha.
        </p>

        @if(! empty($erro))
            <div class="alert alert-danger small py-2">{{ $erro }}</div>
        @endif

        <form id="pv-acesso-form" novalidate>
            @csrf
            <div class="mb-3">
                <label class="form-label small">E-mail</label>
                <input type="email" name="email" class="form-control" required autocomplete="email">
            </div>
            <button type="submit" class="btn btn-dark w-100 py-2 fw-semibold">
                Enviar link de acesso
            </button>
            <div id="pv-acesso-msg" class="alert alert-success small py-2 mt-3 d-none"></div>
        </form>
    </div>
</section>
@endsection

@push('scripts')
    @vite('resources/js/pages/publico/acesso.js')
@endpush
