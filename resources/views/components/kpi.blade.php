@props([
    'label',
    'value' => null,
    'hint' => null,
    'icon' => null,
    'tone' => 'info',
    'href' => null,
    'variant' => 'default',
])

@php
    // Lenguaje del prototipo original (assets/js/ui.js · UI.kpi): cifra en azul institucional y
    // el tono solo en el cuadro del icono, para dar acento sin llenar la vista de color.
    $tonos = [
        'info' => 'bg-inst-50 text-estado-info',
        'success' => 'bg-emerald-50 text-estado-exito',
        'warning' => 'bg-amber-50 text-estado-adv',
        'danger' => 'bg-red-50 text-estado-error',
        'neutral' => 'bg-slate-100 text-tx-soft',
    ];
    $tono = $tonos[$tone === 'default' ? 'info' : $tone] ?? $tonos['info'];
    $compacto = $variant === 'compact';
    $tag = $href ? 'a' : 'article';
    $base = 'block min-w-0 rounded-lg bg-white shadow-card ring-1 ring-slate-900/5 '
        .($compacto ? 'p-3' : 'p-4')
        .($href ? ' transition hover:shadow-pop hover:ring-inst-200' : '');
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->class([$base]) }}>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="{{ $compacto ? '' : 'min-h-8' }} text-xs font-medium uppercase tracking-wide text-tx-soft">{{ $label }}</p>
            <p class="mt-2 {{ $compacto ? 'text-xl' : 'text-2xl' }} font-semibold tabular-nums text-inst-900">{{ $value ?? $slot }}</p>
        </div>
        @if ($icon)
            <span class="flex {{ $compacto ? 'h-8 w-8' : 'h-10 w-10' }} shrink-0 items-center justify-center self-center rounded-lg transition-colors {{ $tono }}" aria-hidden="true">
                <x-icon :name="$icon" class="{{ $compacto ? 'h-4 w-4' : 'h-5 w-5' }}"/>
            </span>
        @endif
    </div>
    @isset($detail)
        <p class="mt-2 text-xs leading-5 text-tx-soft">{{ $detail }}</p>
    @elseif ($hint)
        <p class="mt-2 text-xs leading-5 text-tx-soft">{{ $hint }}</p>
    @endisset
</{{ $tag }}>
