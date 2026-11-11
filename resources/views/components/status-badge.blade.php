@props([
    'label' => null,
    'tone' => 'info',
    'status' => null,
    'icon' => null,
    'showDot' => true,
    'size' => 'sm',
])

@php
    $key = strtolower(trim((string)($status ?? $tone)));

    $tones = [
        // Éxito / completado / aprobado
        'success' => ['badge' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20', 'dot' => 'bg-emerald-600'],
        'completado' => ['badge' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20', 'dot' => 'bg-emerald-600'],
        'correcto' => ['badge' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20', 'dot' => 'bg-emerald-600'],
        'apto' => ['badge' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20', 'dot' => 'bg-emerald-600'],

        // Advertencia / pendiente / revisión
        'warning' => ['badge' => 'bg-amber-50 text-amber-900 ring-amber-600/20', 'dot' => 'bg-amber-600'],
        'pendiente' => ['badge' => 'bg-amber-50 text-amber-900 ring-amber-600/20', 'dot' => 'bg-amber-600'],
        'advertencia' => ['badge' => 'bg-amber-50 text-amber-900 ring-amber-600/20', 'dot' => 'bg-amber-600'],
        'revision' => ['badge' => 'bg-amber-50 text-amber-900 ring-amber-600/20', 'dot' => 'bg-amber-600'],

        // En proceso
        'process' => ['badge' => 'bg-blue-50 text-blue-900 ring-blue-600/20', 'dot' => 'bg-blue-600'],
        'proceso' => ['badge' => 'bg-blue-50 text-blue-900 ring-blue-600/20', 'dot' => 'bg-blue-600'],
        'en_proceso' => ['badge' => 'bg-blue-50 text-blue-900 ring-blue-600/20', 'dot' => 'bg-blue-600'],

        // Peligro / error / descartado / fallido
        'danger' => ['badge' => 'bg-red-50 text-red-800 ring-red-600/20', 'dot' => 'bg-red-600'],
        'error' => ['badge' => 'bg-red-50 text-red-800 ring-red-600/20', 'dot' => 'bg-red-600'],
        'fallido' => ['badge' => 'bg-red-50 text-red-800 ring-red-600/20', 'dot' => 'bg-red-600'],
        'descartado' => ['badge' => 'bg-red-50 text-red-800 ring-red-600/20', 'dot' => 'bg-red-600'],

        // Cancelado / neutral
        'canceled' => ['badge' => 'bg-slate-100 text-slate-700 ring-slate-500/20', 'dot' => 'bg-slate-500'],
        'cancelado' => ['badge' => 'bg-slate-100 text-slate-700 ring-slate-500/20', 'dot' => 'bg-slate-500'],
        'rechazado' => ['badge' => 'bg-slate-100 text-slate-700 ring-slate-500/20', 'dot' => 'bg-slate-500'],

        // Informativo
        'info' => ['badge' => 'bg-inst-50 text-inst-700 ring-inst-700/20', 'dot' => 'bg-inst-700'],
        'informativo' => ['badge' => 'bg-inst-50 text-inst-700 ring-inst-700/20', 'dot' => 'bg-inst-700'],

        // Omitido / Púrpura
        'purple' => ['badge' => 'bg-purple-50 text-purple-800 ring-purple-600/20', 'dot' => 'bg-purple-600'],
        'omitido' => ['badge' => 'bg-purple-50 text-purple-800 ring-purple-600/20', 'dot' => 'bg-purple-600'],
        'excluido' => ['badge' => 'bg-purple-50 text-purple-800 ring-purple-600/20', 'dot' => 'bg-purple-600'],

        // Neutro
        'neutral' => ['badge' => 'bg-slate-100 text-tx-soft ring-slate-500/20', 'dot' => 'bg-slate-400'],
    ];

    $config = $tones[$key] ?? $tones['neutral'];

    $sizes = [
        'xs' => 'px-2 py-0.5 text-[11px] gap-1',
        'sm' => 'px-2.5 py-0.5 text-xs gap-1.5',
        'md' => 'px-3 py-1 text-sm gap-2',
    ];

    $content = $label ?? ($slot->isNotEmpty() ? $slot : '');
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-full font-medium ring-1 ring-inset transition-colors', $config['badge'], $sizes[$size] ?? $sizes['sm']]) }}>
    @if ($icon)
        <x-icon :name="$icon" class="h-3 w-3 shrink-0" />
    @elseif ($showDot)
        <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $config['dot'] }}" aria-hidden="true"></span>
    @endif
    <span>{{ $content }}</span>
</span>
