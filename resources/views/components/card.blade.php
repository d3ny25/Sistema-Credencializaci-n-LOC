@props(['title' => null, 'description' => null, 'icon' => null])

<section {{ $attributes->class(['rounded-lg bg-white shadow-card ring-1 ring-slate-900/5']) }}>
    @if ($title || $description)
        <header class="{{ $icon ? 'flex items-center gap-3' : '' }} border-b border-slate-100 px-5 py-4">
            @if ($icon)
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-inst-50 text-inst-700">
                    <x-icon :name="$icon" class="h-4 w-4"/>
                </span>
                <div class="min-w-0">
            @endif
            @if ($title)
                <h2 class="text-sm font-semibold text-inst-900">{{ $title }}</h2>
            @endif
            @if ($description)
                <p class="mt-1 text-xs leading-5 text-tx-soft">{{ $description }}</p>
            @endif
            @if ($icon)
                </div>
            @endif
        </header>
    @endif
    <div class="flex flex-1 flex-col p-5">
        {{ $slot }}
    </div>
</section>
