{{-- Resumen del expediente y métricas del prefiltro --}}
@php
    $total = (int) $solicitud->total_elementos;
    $aptos = (int) $solicitud->total_aptos;
    $descartados = (int) $solicitud->total_descartados;
    $sinEvaluar = max(0, $total - ($aptos + $descartados));
    $pctAptos = $total > 0 ? round(($aptos / $total) * 100, 1) : 0;
    $pctDescartados = $total > 0 ? round(($descartados / $total) * 100, 1) : 0;
@endphp

<x-card title="Resumen del expediente" description="Datos generales de recepción y balance consolidado de la evaluación.">
    {{-- Bloque superior: Ficha de datos generales del expediente (3 columnas equilibradas) --}}
    <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
        {{-- Tarjeta --}}
        <div class="rounded-lg border border-slate-200/90 bg-slate-50/70 p-3 transition hover:bg-slate-50">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-semibold uppercase tracking-wider text-tx-soft">Número de tarjeta</span>
                <span class="h-2 w-2 rounded-full bg-inst-700" title="Secuencia oficial"></span>
            </div>
            <div class="mt-1 font-mono text-sm font-bold text-inst-900 tracking-tight whitespace-nowrap">
                {{ $solicitud->numero_tarjeta }}
            </div>
            <div class="mt-0.5 text-[11px] text-slate-500">Secuencia institucional fija</div>
        </div>

        {{-- Oficio y Fecha --}}
        <div class="rounded-lg border border-slate-200/90 bg-slate-50/70 p-3 transition hover:bg-slate-50">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-semibold uppercase tracking-wider text-tx-soft">Oficio de remisión</span>
                <span class="text-[11px] font-medium text-slate-500">
                    {{ $solicitud->fecha_recepcion ? $solicitud->fecha_recepcion->format('d/m/Y') : '—' }}
                </span>
            </div>
            <div class="mt-1 font-mono text-sm font-bold text-slate-800 tracking-tight whitespace-nowrap truncate" title="{{ $solicitud->numero_oficio }}">
                {{ $solicitud->numero_oficio }}
            </div>
            <div class="mt-0.5 text-[11px] text-slate-500">Documento de solicitud</div>
        </div>

        {{-- Área solicitante --}}
        <div class="sm:col-span-2 lg:col-span-1 rounded-lg border border-slate-200/90 bg-slate-50/70 p-3 transition hover:bg-slate-50">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-semibold uppercase tracking-wider text-tx-soft">Área solicitante</span>
                @if ($solicitud->area_clave)
                    <span class="inline-flex items-center rounded bg-inst-100/80 px-1.5 py-0.5 font-mono text-[10px] font-bold text-inst-900 border border-inst-200">
                        {{ $solicitud->area_clave }}
                    </span>
                @endif
            </div>
            <div class="mt-1 text-sm font-semibold text-slate-900 leading-snug truncate" title="{{ $solicitud->area_solicitante ?: 'No especificada en el catálogo' }}">
                {{ $solicitud->area_solicitante ?: 'No especificada en el catálogo' }}
            </div>
            <div class="mt-0.5 flex items-center gap-1.5 text-[11px] text-slate-500">
                <span>Estado:</span>
                <span class="font-medium text-slate-700">{{ ucfirst($solicitud->estado_general) }}</span>
            </div>
        </div>
    </div>

    {{-- Bloque inferior: Tarjetas métricas de balance (KPIs) con barra de proporción --}}
    <div class="mt-3.5 pt-3.5 border-t border-slate-100">
        <div class="grid grid-cols-3 gap-2 sm:gap-3">
            {{-- Total Recibidos --}}
            <div class="relative overflow-hidden rounded-lg border border-slate-200 bg-white p-3 sm:p-4 shadow-2xs">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] sm:text-xs font-semibold text-slate-600 truncate">Total recibidos</span>
                    <span class="hidden sm:inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-700">
                        100%
                    </span>
                </div>
                <div class="mt-1 sm:mt-2 flex items-baseline gap-1 sm:gap-2">
                    <span class="font-mono text-2xl sm:text-3xl font-extrabold text-inst-900">{{ $total }}</span>
                    <span class="hidden sm:inline text-xs text-tx-soft font-medium">elementos</span>
                </div>
                <p class="mt-0.5 text-[10px] sm:text-[11px] text-slate-500 truncate">Filas en archivo Excel</p>
            </div>

            {{-- Elementos Aptos --}}
            <div class="relative overflow-hidden rounded-lg border border-emerald-200/90 bg-emerald-50/30 p-3 sm:p-4 shadow-2xs">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] sm:text-xs font-semibold text-emerald-900 truncate">Aptos</span>
                    <span class="inline-flex items-center gap-0.5 rounded-full bg-emerald-100/90 px-1.5 sm:px-2 py-0.5 text-[10px] sm:text-[11px] font-bold text-emerald-800">
                        <svg class="h-2.5 w-2.5 sm:h-3 sm:w-3 text-emerald-600 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                        </svg>
                        {{ $pctAptos }}%
                    </span>
                </div>
                <div class="mt-1 sm:mt-2 flex items-baseline gap-1 sm:gap-2">
                    <span class="font-mono text-2xl sm:text-3xl font-extrabold text-emerald-700">{{ $aptos }}</span>
                    <span class="hidden sm:inline text-xs font-medium text-emerald-800">dictaminados</span>
                </div>
                <p class="mt-0.5 text-[10px] sm:text-[11px] text-emerald-700 truncate">Cumplen requisitos</p>
            </div>

            {{-- Elementos Descartados --}}
            <div class="relative overflow-hidden rounded-lg border border-rose-200/90 bg-rose-50/30 p-3 sm:p-4 shadow-2xs">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] sm:text-xs font-semibold text-rose-900 truncate">Descartados</span>
                    <span class="inline-flex items-center gap-0.5 rounded-full bg-rose-100/90 px-1.5 sm:px-2 py-0.5 text-[10px] sm:text-[11px] font-bold text-rose-800">
                        <svg class="h-2.5 w-2.5 sm:h-3 sm:w-3 text-rose-600 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        </svg>
                        {{ $pctDescartados }}%
                    </span>
                </div>
                <div class="mt-1 sm:mt-2 flex items-baseline gap-1 sm:gap-2">
                    <span class="font-mono text-2xl sm:text-3xl font-extrabold text-rose-700">{{ $descartados }}</span>
                    <span class="hidden sm:inline text-xs font-medium text-rose-800">con descarte</span>
                </div>
                <p class="mt-0.5 text-[10px] sm:text-[11px] text-rose-700 truncate">Motivos clasificados</p>
            </div>
        </div>

        {{-- Barra visual de distribución proporcional --}}
        @if ($total > 0)
            <div class="mt-3">
                <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100 flex">
                    @if ($aptos > 0)
                        <div style="width: {{ $pctAptos }}%" class="h-full bg-emerald-500 transition-all duration-300" title="Aptos: {{ $aptos }} ({{ $pctAptos }}%)"></div>
                    @endif
                    @if ($descartados > 0)
                        <div style="width: {{ $pctDescartados }}%" class="h-full bg-rose-500 transition-all duration-300" title="Descartados: {{ $descartados }} ({{ $pctDescartados }}%)"></div>
                    @endif
                    @if ($sinEvaluar > 0)
                        <div style="width: {{ round(($sinEvaluar / $total) * 100, 1) }}%" class="h-full bg-slate-300" title="Sin evaluar: {{ $sinEvaluar }}"></div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</x-card>

{{-- Snapshot inmutable de fuentes oficiales si la evaluación ya se ejecutó --}}
@if ($solicitud->fecha_prefiltro && !empty($solicitud->fuentes_evaluacion))
    <div class="mt-4">
        <x-card title="Fuentes oficiales utilizadas en el prefiltro" description="Trazabilidad y snapshot inmutable de los lotes y catálogo oficiales al momento de la evaluación.">
            <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-4">
                {{-- Personal --}}
                <div class="rounded-lg border border-slate-200/90 bg-slate-50/70 p-3 transition hover:bg-slate-50">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-inst-900">Personal Autorizado LOC</span>
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" title="Lote verificado"></span>
                    </div>
                    <div class="mt-1 flex items-baseline gap-1.5">
                        <span class="text-xs text-slate-500">Lote:</span>
                        <span class="font-mono text-xs font-bold text-slate-800">#{{ $solicitud->fuentes_evaluacion['personal']['batch_id'] ?? ($solicitud->fuentes_evaluacion['personal_batch_id'] ?? '—') }}</span>
                    </div>
                    <p class="mt-0.5 text-[11px] text-slate-500 truncate font-mono" title="{{ $solicitud->fuentes_evaluacion['personal']['archivo'] ?? '' }}">
                        {{ $solicitud->fuentes_evaluacion['personal']['archivo'] ?? 'Lote confirmado' }}
                    </p>
                </div>

                {{-- Armamento --}}
                <div class="rounded-lg border border-slate-200/90 bg-slate-50/70 p-3 transition hover:bg-slate-50">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-inst-900">Armamento LOC</span>
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" title="Lote verificado"></span>
                    </div>
                    <div class="mt-1 flex items-baseline gap-1.5">
                        <span class="text-xs text-slate-500">Lote:</span>
                        <span class="font-mono text-xs font-bold text-slate-800">#{{ $solicitud->fuentes_evaluacion['armamento']['batch_id'] ?? ($solicitud->fuentes_evaluacion['armamento_batch_id'] ?? '—') }}</span>
                    </div>
                    <p class="mt-0.5 text-[11px] text-slate-500 truncate font-mono" title="{{ $solicitud->fuentes_evaluacion['armamento']['archivo'] ?? '' }}">
                        {{ $solicitud->fuentes_evaluacion['armamento']['archivo'] ?? 'Lote confirmado' }}
                    </p>
                </div>

                {{-- Vigencias --}}
                <div class="rounded-lg border border-slate-200/90 bg-slate-50/70 p-3 transition hover:bg-slate-50">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-inst-900">Vigencias Credenciales</span>
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" title="Lote verificado"></span>
                    </div>
                    <div class="mt-1 flex items-baseline gap-1.5">
                        <span class="text-xs text-slate-500">Lote:</span>
                        <span class="font-mono text-xs font-bold text-slate-800">#{{ $solicitud->fuentes_evaluacion['vigencias']['batch_id'] ?? ($solicitud->fuentes_evaluacion['vigencias_batch_id'] ?? '—') }}</span>
                    </div>
                    <p class="mt-0.5 text-[11px] text-slate-500 truncate font-mono" title="{{ $solicitud->fuentes_evaluacion['vigencias']['archivo'] ?? '' }}">
                        {{ $solicitud->fuentes_evaluacion['vigencias']['archivo'] ?? 'Lote confirmado' }}
                    </p>
                </div>

                {{-- Catálogo --}}
                <div class="rounded-lg border border-slate-200/90 bg-slate-50/70 p-3 transition hover:bg-slate-50">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold text-inst-900">Catálogo Organizacional</span>
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" title="Versión activa"></span>
                    </div>
                    <div class="mt-1 flex items-baseline gap-1.5">
                        <span class="text-xs text-slate-500">Versión:</span>
                        <span class="font-mono text-xs font-bold text-slate-800">#{{ $solicitud->fuentes_evaluacion['catalogo']['version_numero'] ?? ($solicitud->fuentes_evaluacion['catalogo_version_id'] ?? '1') }}</span>
                    </div>
                    <p class="mt-0.5 text-[11px] text-slate-500 truncate font-mono" title="{{ $solicitud->fuentes_evaluacion['catalogo']['archivo'] ?? '' }}">
                        {{ $solicitud->fuentes_evaluacion['catalogo']['archivo'] ?? 'Catálogo vigente' }}
                    </p>
                </div>
            </div>

            {{-- Metadatos de la evaluación --}}
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-2.5 text-xs text-slate-500">
                <div class="flex items-center gap-1.5">
                    <svg class="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>Fecha de evaluación:</span>
                    <span class="font-mono font-medium text-slate-700">
                        {{ $solicitud->fecha_prefiltro ? $solicitud->fecha_prefiltro->format('d/m/Y H:i:s') : '—' }}
                    </span>
                </div>
                <div class="flex items-center gap-1.5">
                    <svg class="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                    <span>Responsable:</span>
                    <span class="font-medium text-slate-700">
                        {{ $solicitud->usuarioPrefiltro?->name ?? 'Daniel Benitez' }}
                    </span>
                </div>
            </div>
        </x-card>
    </div>
@endif
