{{--
    Logo da marca. Duas variantes do mesmo arquivo:
      - escura (traço preto)  → fundos claros  [default]
      - clara  (traço branco) → fundos escuros

    Uso: @include('theme::partials.logo', ['variante' => 'clara', 'altura' => 40])

    `altura` em px controla o tamanho (a largura acompanha, o arquivo é 357x171).
    Sem texto ao lado: o nome da marca já faz parte do desenho.
--}}
@php
    $variante = $variante ?? 'escura';
    $altura = $altura ?? 36;
    $classe = $classe ?? '';
@endphp
<img src="{{ asset('img/logo-' . $variante . '.png') }}"
     alt="{{ config('app.name') }}"
     class="pv-logo {{ $classe }}"
     height="{{ $altura }}"
     style="height: {{ $altura }}px; width: auto;"
     loading="eager"
     decoding="async">
