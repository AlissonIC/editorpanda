@props(['label', 'value', 'icon' => 'bi-graph-up', 'color' => 'primary'])
<div class="panda-card">
    <div class="panda-stat">
        <div class="icon icon-{{ $color }}"><i class="bi {{ $icon }}"></i></div>
        <div>
            <div class="label">{{ $label }}</div>
            <div class="value">{{ $value }}</div>
        </div>
    </div>
</div>
