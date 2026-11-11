@props(['items', 'active'])

<nav class="mb-5 overflow-x-auto border-b border-slate-200" aria-label="Secciones del módulo">
    <div class="flex min-w-max gap-5">
        @foreach ($items as $item)
            <a href="{{ route($item['route'], $item['parameters'] ?? []) }}"
               @if ($active === $item['key']) aria-current="page" @endif
               class="border-b-2 px-1 pb-3 text-sm font-medium transition-colors {{ $active === $item['key'] ? 'border-inst-700 text-inst-900' : 'border-transparent text-tx-soft hover:border-slate-300 hover:text-tx' }}">
                {{ $item['label'] }}
            </a>
        @endforeach
    </div>
</nav>
