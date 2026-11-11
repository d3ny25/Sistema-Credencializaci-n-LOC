@props([
    'reasons' => [],
    'codes' => [],
    'rechazo' => null,
    'label' => 'Descartado',
    'tone' => 'danger',
    'mode' => 'popover', // 'popover' or 'inline'
    'emptyText' => null,
])

@php
    if (is_string($reasons)) {
        $reasonsList = array_filter([$reasons]);
    } elseif (is_iterable($reasons)) {
        $reasonsList = is_array($reasons) ? $reasons : iterator_to_array($reasons);
        $reasonsList = array_values(array_filter($reasonsList));
    } else {
        $reasonsList = [];
    }

    if (empty($reasonsList) && !empty($rechazo)) {
        $reasonsList = [trim((string) $rechazo)];
    }

    $codesList = is_array($codes) ? $codes : (is_iterable($codes) ? iterator_to_array($codes) : []);
    $codesList = array_values(array_filter($codesList));

    $count = count($reasonsList);
    $codesCount = count($codesList);
    $esHistorico = ($count > 0 || !empty($rechazo)) && $codesCount === 0;

    $tones = [
        'danger' => [
            'badge' => 'bg-red-50 text-estado-error ring-red-600/20 hover:bg-red-100/80',
            'dot' => 'bg-estado-error',
            'border' => 'border-red-200',
        ],
        'warning' => [
            'badge' => 'bg-amber-50 text-estado-adv ring-amber-600/20 hover:bg-amber-100/80',
            'dot' => 'bg-estado-adv',
            'border' => 'border-amber-200',
        ],
        'info' => [
            'badge' => 'bg-blue-50 text-estado-info ring-blue-600/20 hover:bg-blue-100/80',
            'dot' => 'bg-estado-info',
            'border' => 'border-blue-200',
        ],
    ];

    $selectedTone = $tones[$tone] ?? $tones['danger'];

    // Determinación del texto principal de la etiqueta y el indicador de motivos adicionales
    $primaryLabel = null;
    $extraCount = 0;

    if ($codesCount > 0) {
        $primaryLabel = \App\Support\MotivoDescarte::label($codesList[0]);
        if ($codesCount > 1) {
            $extraCount = $codesCount - 1;
        }
    } elseif ($count > 0) {
        $primaryLabel = $label . ($count === 1 ? ' (1 motivo)' : " ({$count} motivos)");
    } else {
        $primaryLabel = $label;
    }
@endphp

@if ($count === 0 && $codesCount === 0 && empty($rechazo))
    @if ($emptyText)
        <span {{ $attributes->class(['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset', $selectedTone['badge']]) }}>
            {{ $emptyText }}
        </span>
    @elseif ($label)
        <span {{ $attributes->class(['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset', $selectedTone['badge']]) }}>
            {{ $label }}
        </span>
    @endif
@else
    @if ($mode === 'inline')
        <div {{ $attributes->class(['space-y-1.5']) }}>
            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset {{ $selectedTone['badge'] }}">
                <span class="h-1.5 w-1.5 rounded-full {{ $selectedTone['dot'] }}" aria-hidden="true"></span>
                <span>{{ $primaryLabel }}</span>
                @if ($extraCount > 0)
                    <span class="ml-1 rounded bg-red-200/60 px-1 py-0.2 text-[10px] font-bold text-red-900">+{{ $extraCount }} más</span>
                @endif
            </span>
            <ul class="list-disc pl-4 space-y-0.5 text-xs text-tx-soft" role="list">
                @foreach ($reasonsList as $index => $reason)
                    <li>{{ $reason }}</li>
                @endforeach
            </ul>
        </div>
    @else
        <details {{ $attributes->class(['group relative inline-block text-left open:z-40']) }}
                 x-data
                 @click.outside="$el.removeAttribute('open')"
                 @keydown.escape="$el.removeAttribute('open')">
            <summary class="inline-flex cursor-pointer select-none items-center gap-1 max-w-full rounded-full px-2 py-0.5 text-[10.5px] font-medium ring-1 ring-inset transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-inst-700 focus-visible:ring-offset-1 {{ $selectedTone['badge'] }}"
                     title="{{ $primaryLabel }} · Clic para consultar detalle de motivos"
                     aria-haspopup="true">
                <span class="h-1.5 w-1.5 rounded-full shrink-0 {{ $selectedTone['dot'] }}" aria-hidden="true"></span>
                <span class="font-medium truncate max-w-[135px]">{{ $primaryLabel }}</span>
                @if ($extraCount > 0)
                    <span class="inline-flex items-center rounded-full bg-red-200/80 px-1 py-0.2 text-[9px] font-bold text-red-900 shadow-2xs shrink-0">
                        +{{ $extraCount }}
                    </span>
                @endif
                <svg class="h-3 w-3 shrink-0 opacity-60 transition-transform duration-200 group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
            </summary>

            <div class="absolute right-0 sm:right-auto sm:left-0 z-50 mt-1.5 w-80 sm:w-96 max-w-[calc(100vw-2rem)] rounded-lg bg-white p-3.5 shadow-xl ring-1 ring-slate-900/10 focus:outline-none drop-shadow-md text-left"
                 role="region"
                 aria-label="Detalle institucional de motivos de descarte">
                <div class="flex items-center justify-between border-b border-slate-100 pb-2 mb-2.5">
                    <div class="flex items-center gap-1.5">
                        <span class="h-2 w-2 rounded-full {{ $selectedTone['dot'] }}" aria-hidden="true"></span>
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-800">
                            @if ($codesCount > 0)
                                Motivos clasificados ({{ $codesCount }})
                            @else
                                Motivos registrados ({{ max(1, $count) }})
                            @endif
                        </span>
                    </div>
                    <button type="button"
                            @click="$el.closest('details').removeAttribute('open')"
                            class="text-slate-400 hover:text-slate-600 rounded p-1 hover:bg-slate-100 transition focus:outline-none focus:ring-1 focus:ring-inst-700"
                            aria-label="Cerrar panel de motivos">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                    </button>
                </div>

                @if ($esHistorico)
                    <div class="mb-2.5 rounded bg-amber-50 p-2 text-[11px] text-amber-800 border border-amber-200 leading-snug">
                        <span class="font-semibold">Registro histórico:</span> Este elemento fue evaluado con anterioridad a la clasificación estructurada de motivos de la Fase 2C. Se preserva íntegro el texto original registrado.
                    </div>
                @endif

                @if (!empty($codesList))
                    <div class="mb-2.5 flex flex-wrap gap-1.5 border-b border-slate-100 pb-2.5">
                        @foreach ($codesList as $code)
                            <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold bg-red-100/90 text-red-900 border border-red-200"
                                  title="Código: {{ $code }} · {{ \App\Support\MotivoDescarte::description($code) }}">
                                {{ \App\Support\MotivoDescarte::label($code) }}
                            </span>
                        @endforeach
                    </div>
                @endif

                @if (!empty($reasonsList))
                    <div class="space-y-1.5">
                        <span class="text-[10px] font-semibold uppercase tracking-wider text-slate-400 block">
                            Detalle registrado por el prefiltro:
                        </span>
                        <ul class="list-disc pl-4 space-y-1 text-xs text-slate-700 leading-relaxed max-h-48 overflow-y-auto" role="list">
                            @foreach ($reasonsList as $index => $reason)
                                <li>{{ $reason }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </details>
    @endif
@endif
