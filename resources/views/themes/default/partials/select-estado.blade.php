@php
    $name = $name ?? 'localizacao_estado';
    $class = $class ?? 'form-select';
    $selected = $selected ?? '';
    $estados = [
        'Acre', 'Alagoas', 'Amapá', 'Amazonas', 'Bahia', 'Ceará',
        'Distrito Federal', 'Espírito Santo', 'Goiás', 'Maranhão',
        'Mato Grosso', 'Mato Grosso do Sul', 'Minas Gerais', 'Pará',
        'Paraíba', 'Paraná', 'Pernambuco', 'Piauí', 'Rio de Janeiro',
        'Rio Grande do Norte', 'Rio Grande do Sul', 'Rondônia', 'Roraima',
        'Santa Catarina', 'São Paulo', 'Sergipe', 'Tocantins',
    ];
@endphp
<select name="{{ $name }}" class="{{ $class }}">
    <option value="">Selecione o estado…</option>
    @foreach($estados as $estado)
        <option value="{{ $estado }}" @selected($selected === $estado)>{{ $estado }}</option>
    @endforeach
</select>
