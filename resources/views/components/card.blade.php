@props(['title' => null, 'description' => null, 'icon' => null])

<section {{ $attributes->class(['panel flex flex-col']) }}>
    @if ($title || $description || isset($actions))
        <header class="flex flex-wrap items-start gap-3 border-b border-linea px-5 py-4">
            @if ($icon)
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-inst-50 text-inst-700">
                    <x-icon :name="$icon" class="h-4 w-4"/>
                </span>
            @endif
            <div class="min-w-0 flex-1">
                @if ($title)
                    <h2 class="section-title">{{ $title }}</h2>
                @endif
                @if ($description)
                    <p class="mt-1 text-xs leading-5 text-tx-soft">{{ $description }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif
    <div class="flex flex-1 flex-col p-5">
        {{ $slot }}
    </div>
</section>
