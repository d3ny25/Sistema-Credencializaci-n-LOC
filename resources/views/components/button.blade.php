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
    // Mismas clases que el marcado directo (resources/css/app.css · .btn-*): una sola apariencia.
    $baseStyles = 'btn';

    $variants = [
        'primary' => 'btn-primary',
        'secondary' => 'btn-secondary',
        'ghost' => 'btn-ghost',
        'success' => 'btn-success',
        'warning' => 'btn-warning',
        'danger' => 'btn-danger',
    ];

    $sizes = [
        'sm' => 'btn-sm',
        'md' => '',
        'lg' => 'btn-lg',
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

