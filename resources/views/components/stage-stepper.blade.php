@props(['steps', 'label' => 'Etapas del proceso'])

{{-- steps: [['label' => ..., 'hint' => ..., 'state' => 'done'|'current'|'pending'], ...] --}}
<nav {{ $attributes->class(['w-full']) }} aria-label="{{ $label }}">
    <ol class="grid gap-2 sm:grid-flow-col sm:auto-cols-fr sm:gap-0">
        @foreach ($steps as $step)
            @php
                $state = $step['state'] ?? 'pending';
                $estadoTexto = ['done' => 'completada', 'current' => 'etapa actual', 'pending' => 'pendiente'][$state];
            @endphp
            <li class="relative flex items-start gap-3 sm:flex-col sm:gap-2 sm:pr-4" @if ($state === 'current') aria-current="step" @endif>
                @unless ($loop->last)
                    <span class="absolute left-[2.25rem] right-0 top-3.5 hidden h-0.5 sm:block {{ $state === 'done' ? 'bg-inst-600' : 'bg-slate-200' }}" aria-hidden="true"></span>
                @endunless
                <span class="relative z-10 flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold ring-4 ring-white
                    {{ $state === 'done' ? 'bg-inst-700 text-white' : ($state === 'current' ? 'bg-white text-inst-800 ring-inst-100 outline outline-2 outline-inst-600' : 'bg-slate-100 text-slate-500') }}">
                    @if ($state === 'done')
                        <x-icon name="check" class="h-3.5 w-3.5"/>
                    @else
                        {{ $loop->iteration }}
                    @endif
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-semibold {{ $state === 'pending' ? 'text-tx-soft' : 'text-inst-900' }}">{{ $step['label'] }}</span>
                    <span class="block text-2xs {{ $state === 'current' ? 'font-medium text-inst-700' : 'text-tx-soft' }}">
                        {{ $step['hint'] ?? '' }}<span class="sr-only"> ({{ $estadoTexto }})</span>
                    </span>
                </span>
            </li>
        @endforeach
    </ol>
</nav>
