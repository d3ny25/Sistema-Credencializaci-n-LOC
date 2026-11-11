@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'href' => null,
    'loading' => false,
    'loadingText' => null,
    'icon' => null,
    'iconPosition' => 'left',
    'disabled' => false,
])

@php
    $baseStyles = 'inline-flex items-center justify-center font-semibold rounded-md transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 select-none';

    $variants = [
        'primary' => 'bg-inst-700 text-white shadow-sm hover:bg-inst-900 active:bg-inst-900 focus-visible:outline-inst-700 disabled:bg-slate-200 disabled:text-slate-500 disabled:shadow-none disabled:cursor-not-allowed',
        'secondary' => 'border border-slate-300 bg-white text-tx shadow-sm hover:bg-slate-50 active:bg-slate-100 focus-visible:outline-inst-700 disabled:bg-slate-100 disabled:text-slate-400 disabled:border-slate-200 disabled:shadow-none disabled:cursor-not-allowed',
        'success' => 'bg-estado-exito text-white shadow-sm hover:bg-emerald-800 active:bg-emerald-900 focus-visible:outline-emerald-700 disabled:bg-slate-200 disabled:text-slate-500 disabled:shadow-none disabled:cursor-not-allowed',
        'warning' => 'bg-estado-adv text-white shadow-sm hover:bg-amber-700 active:bg-amber-800 focus-visible:outline-amber-600 disabled:bg-slate-200 disabled:text-slate-500 disabled:shadow-none disabled:cursor-not-allowed',
        'danger' => 'bg-estado-error text-white shadow-sm hover:bg-red-800 active:bg-red-900 focus-visible:outline-red-700 disabled:bg-slate-200 disabled:text-slate-500 disabled:shadow-none disabled:cursor-not-allowed',
    ];

    $sizes = [
        'sm' => 'px-3 py-1.5 text-xs gap-1.5',
        'md' => 'px-4 py-2 text-sm gap-2',
        'lg' => 'px-5 py-2.5 text-base gap-2.5',
    ];

    $iconSizes = [
        'sm' => 'h-3.5 w-3.5',
        'md' => 'h-4 w-4',
        'lg' => 'h-5 w-5',
    ];

    $isDisabled = $disabled || $loading;
    $computedClasses = trim($baseStyles . ' ' . ($variants[$variant] ?? $variants['primary']) . ' ' . ($sizes[$size] ?? $sizes['md']));
    $disabledAnchorClasses = 'opacity-60 cursor-not-allowed pointer-events-none bg-slate-200 text-slate-500 border-transparent shadow-none';
@endphp

@if ($href)
    <a href="{{ $href }}"
       role="button"
       {{ $attributes->merge(['class' => $isDisabled ? trim($baseStyles . ' ' . $disabledAnchorClasses . ' ' . ($sizes[$size] ?? $sizes['md'])) : $computedClasses]) }}
       @if ($isDisabled) aria-disabled="true" tabindex="-1" onclick="return false;" @endif>
        @if ($loading)
            <svg class="animate-spin {{ $iconSizes[$size] ?? 'h-4 w-4' }} text-current" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
            </svg>
            <span>{{ $loadingText ?? $slot }}</span>
        @else
            @if ($icon && $iconPosition === 'left')
                <x-icon :name="$icon" :class="$iconSizes[$size] ?? 'h-4 w-4'" />
            @endif
            <span>{{ $slot }}</span>
            @if ($icon && $iconPosition === 'right')
                <x-icon :name="$icon" :class="$iconSizes[$size] ?? 'h-4 w-4'" />
            @endif
        @endif
    </a>
@else
    <button type="{{ $type }}"
            {{ $attributes->merge(['class' => $computedClasses]) }}
            @if ($isDisabled) disabled @endif
            @if ($loading) aria-busy="true" @endif>
        @if ($loading)
            <svg class="animate-spin {{ $iconSizes[$size] ?? 'h-4 w-4' }} text-current" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
            </svg>
            <span>{{ $loadingText ?? $slot }}</span>
        @else
            @if ($icon && $iconPosition === 'left')
                <x-icon :name="$icon" :class="$iconSizes[$size] ?? 'h-4 w-4'" />
            @endif
            <span>{{ $slot }}</span>
            @if ($icon && $iconPosition === 'right')
                <x-icon :name="$icon" :class="$iconSizes[$size] ?? 'h-4 w-4'" />
            @endif
        @endif
    </button>
@endif

