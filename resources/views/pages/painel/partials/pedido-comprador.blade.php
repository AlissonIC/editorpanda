{{-- Nome e e-mail empilhados numa célula só — economiza a largura que duas
     colunas ocupavam sem perder nenhuma das duas informações. --}}
<div class="lh-sm">
    <div class="fw-semibold">{{ $nome ?: '—' }}</div>
    @if($email)
        <a href="mailto:{{ $email }}" class="small text-muted text-decoration-none">{{ $email }}</a>
    @endif
</div>
