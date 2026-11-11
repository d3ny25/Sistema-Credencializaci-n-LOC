{{-- Tabla de elementos importados y tarjetas móviles (Fragmento Reactivo AJAX) --}}
@php
    $hasActiveFilters = !empty($filters) && count(array_filter($filters)) > 0;
    $clearFiltersUrl = route('credencializacion.detalle', $solicitud->id);
@endphp

{{-- ========================================== --}}
{{-- 1. VISTA TABULAR DE ESCRITORIO (md: y sup) --}}
{{-- ========================================== --}}
<div class="hidden md:block -mx-5 -mb-5 overflow-x-auto rounded-b-lg border-t border-slate-200 bg-white">
    <table class="w-full table-fixed border-collapse text-left">
        <thead class="bg-slate-50/95 border-b border-slate-200 text-[10px] sm:text-[11px] font-bold uppercase tracking-wider text-slate-600">
            <tr>
                <th scope="col" class="w-[45px] px-1.5 py-3 text-center"># / Fila</th>
                <th scope="col" class="w-[150px] px-2 py-3">CUIP</th>
                <th scope="col" class="w-[170px] px-2 py-3">Persona</th>
                <th scope="col" class="w-[165px] px-2 py-3">Agrupamiento Institucional</th>
                <th scope="col" class="w-[125px] px-2 py-3">Arma Corta</th>
                <th scope="col" class="w-[125px] px-2 py-3">Arma Larga</th>
                <th scope="col" class="w-[90px] px-2 py-3 text-center">Prefiltro / Motivos</th>
                <th scope="col" class="w-[90px] px-2 py-3 text-center">Estado Trámite</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 text-xs">
            @forelse ($elementos as $elemento)
                <tr class="hover:bg-inst-50/25 transition-colors">
                    {{-- 1. # / Fila Excel (45 px) --}}
                    <td class="w-[45px] px-1.5 py-2 text-center whitespace-nowrap">
                        <div class="font-mono text-xs font-bold text-slate-800">
                            #{{ $elementos instanceof \Illuminate\Pagination\LengthAwarePaginator ? ($elementos->firstItem() + $loop->index) : $loop->iteration }}
                        </div>
                        @if ($elemento->numero_fila)
                            <div class="font-mono text-[9px] text-slate-400" title="Fila #{{ $elemento->numero_fila }} en archivo Excel">
                                F. {{ $elemento->numero_fila }}
                            </div>
                        @endif
                    </td>

                    {{-- 2. CUIP (150 px) --}}
                    <td class="w-[150px] px-2 py-2 whitespace-nowrap">
                        @if ($elemento->cuip && !\App\Services\SolicitudPrefiltroService::isCuipPlaceholder($elemento->cuip))
                            <div class="inline-flex items-center gap-1 font-mono text-[11px] font-bold text-inst-900 tracking-tight" title="Clave Única de Identificación Policial">
                                <span>{{ $elemento->cuip }}</span>
                                <button type="button"
                                        onclick="navigator.clipboard.writeText('{{ $elemento->cuip }}'); this.classList.add('text-emerald-600'); setTimeout(() => this.classList.remove('text-emerald-600'), 1500);"
                                        class="rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-inst-700 transition"
                                        title="Copiar CUIP">
                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                    </svg>
                                </button>
                            </div>
                        @else
                            <span class="italic text-slate-400 text-[10px]">Sin CUIP</span>
                        @endif
                    </td>

                    {{-- 3. Persona (170 px) --}}
                    <td class="w-[170px] max-w-[170px] px-2 py-2">
                        @php
                            $nombreOficial = $elemento->persona?->nombre;
                            $nombreSolicitado = $elemento->datos_solicitados['nombre'] ?? ($elemento->datos_originales_fila['NOMBRE'] ?? null);
                            $categoria = $elemento->persona?->categoria ?? ($elemento->datos_solicitados['categoria'] ?? null);
                            $municipio = $elemento->persona?->municipio ?? ($elemento->datos_solicitados['municipio'] ?? null);
                        @endphp

                        @if ($nombreOficial)
                            <div class="font-bold text-slate-900 text-xs leading-tight truncate" title="{{ $nombreOficial }}">
                                {{ $nombreOficial }}
                            </div>
                            @if ($nombreSolicitado && strtoupper(trim((string)$nombreSolicitado)) !== strtoupper(trim((string)$nombreOficial)))
                                <div class="text-[10px] text-amber-700 font-medium truncate" title="Nombre solicitado en Excel: {{ $nombreSolicitado }}">
                                    <span class="font-semibold">Excel:</span> {{ $nombreSolicitado }}
                                </div>
                            @endif
                        @elseif ($nombreSolicitado)
                            <div class="font-semibold text-slate-800 text-xs leading-tight truncate" title="{{ $nombreSolicitado }}">
                                {{ $nombreSolicitado }}
                            </div>
                            <div class="text-[10px] text-slate-400 italic">No vinculado oficial</div>
                        @else
                            <span class="text-slate-400">—</span>
                        @endif

                        @if ($categoria || $municipio)
                            <div class="text-[10px] text-slate-500 truncate mt-0.5" title="{{ trim(($categoria ?? '') . ' · ' . ($municipio ?? '')) }}">
                                {{ $categoria ?? '' }}
                                {{ ($categoria && $municipio) ? '· ' : '' }}
                                {{ $municipio ?? '' }}
                            </div>
                        @endif
                    </td>

                    {{-- 4. Agrupamiento Institucional (165 px) --}}
                    <td class="w-[165px] max-w-[165px] px-2 py-2">
                        @php
                            $agrInfo = \App\Support\AgrupamientoInstitucional::resolver($elemento, $catalogo ?? null);
                        @endphp

                        @if ($agrInfo['estado'] === 'verificado')
                            <div class="max-w-[149px]">
                                <div class="inline-flex items-center gap-1 max-w-full rounded bg-inst-50/90 px-1.5 py-0.5 text-[10px] font-medium text-inst-900 border border-inst-200/80 shadow-2xs"
                                     title="[{{ $agrInfo['clave'] }}] {{ $agrInfo['denominacion'] }}">
                                    <span class="font-mono font-bold text-inst-700 shrink-0">[{{ $agrInfo['clave'] }}]</span>
                                    <span class="truncate text-slate-800">{{ $agrInfo['denominacion'] }}</span>
                                </div>
                            </div>
                        @elseif ($agrInfo['estado'] === 'discrepante')
                            <div class="max-w-[149px] space-y-0.5">
                                <div class="inline-flex items-center gap-1 rounded bg-amber-50 px-1.5 py-0.2 text-[9px] font-semibold text-amber-800 border border-amber-200"
                                     title="Discrepancia detectada: La adscripción solicitada en Excel difiere del registro oficial en plantilla">
                                    <svg class="h-2.5 w-2.5 text-amber-600 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                    </svg>
                                    <span>Discrepante</span>
                                </div>
                                <div class="text-[10px] text-slate-700 leading-tight truncate" title="Excel: {{ $agrInfo['solicitado'] }}">
                                    <span class="text-slate-400">Excel:</span> {{ $agrInfo['solicitado'] }}
                                </div>
                            </div>
                        @elseif ($agrInfo['estado'] === 'ambiguo')
                            <div class="max-w-[149px] space-y-0.5">
                                <div class="inline-flex items-center gap-1 rounded bg-amber-50 px-1.5 py-0.2 text-[9px] font-semibold text-amber-800 border border-amber-200"
                                     title="{{ $agrInfo['advertencia'] }}">
                                    <svg class="h-2.5 w-2.5 text-amber-600 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                    </svg>
                                    <span>Ambiguo</span>
                                </div>
                                <div class="text-[10px] text-slate-700 italic truncate" title="{{ $agrInfo['solicitado'] ?: $agrInfo['persona'] }}">
                                    {{ $agrInfo['solicitado'] ?: $agrInfo['persona'] }}
                                </div>
                            </div>
                        @elseif ($agrInfo['estado'] === 'sin_clave')
                            <div class="max-w-[149px]">
                                <div class="text-[10px] text-slate-700 italic truncate" title="{{ $agrInfo['solicitado'] ?: $agrInfo['persona'] }}">
                                    {{ $agrInfo['solicitado'] ?: $agrInfo['persona'] }}
                                </div>
                                <div class="text-[9px] text-slate-400">Sin clave oficial</div>
                            </div>
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>

                    {{-- 5. Arma Corta (125 px) --}}
                    <td class="w-[125px] max-w-[125px] px-2 py-2">
                        @if ($elemento->armaCorta)
                            <div class="font-mono text-xs font-bold text-slate-800 tracking-tight truncate" title="Matrícula oficial: {{ $elemento->armaCorta->matricula }}">
                                {{ $elemento->armaCorta->matricula }}
                            </div>
                            <div class="text-[10px] text-slate-500 leading-tight mt-0.5 truncate" title="{{ $elemento->armaCorta->marca }} {{ $elemento->armaCorta->modelo }} · {{ $elemento->armaCorta->calibre }}">
                                {{ $elemento->armaCorta->marca }} {{ $elemento->armaCorta->modelo }}
                            </div>
                            @if ($elemento->matricula_corta && strtoupper(trim((string)$elemento->matricula_corta)) !== strtoupper(trim((string)$elemento->armaCorta->matricula)))
                                <div class="text-[9px] text-amber-700 font-mono truncate" title="Matrícula solicitada: {{ $elemento->matricula_corta }}">
                                    Sol: {{ $elemento->matricula_corta }}
                                </div>
                            @endif
                        @elseif ($elemento->matricula_corta)
                            <div class="font-mono text-xs font-semibold text-slate-700 truncate" title="{{ $elemento->matricula_corta }}">
                                {{ $elemento->matricula_corta }}
                            </div>
                            <span class="inline-flex items-center rounded bg-amber-50 px-1 py-0.2 text-[9px] font-medium text-amber-800 border border-amber-200 mt-0.5"
                                  title="Matrícula solicitada no vinculada al catálogo oficial">
                                No vinculada
                            </span>
                        @else
                            <span class="text-slate-400 italic text-[10px]">Sin arma</span>
                        @endif
                    </td>

                    {{-- 6. Arma Larga (125 px) --}}
                    <td class="w-[125px] max-w-[125px] px-2 py-2">
                        @if ($elemento->armaLarga)
                            <div class="font-mono text-xs font-bold text-slate-800 tracking-tight truncate" title="Matrícula oficial: {{ $elemento->armaLarga->matricula }}">
                                {{ $elemento->armaLarga->matricula }}
                            </div>
                            <div class="text-[10px] text-slate-500 leading-tight mt-0.5 truncate" title="{{ $elemento->armaLarga->marca }} {{ $elemento->armaLarga->modelo }} · {{ $elemento->armaLarga->calibre }}">
                                {{ $elemento->armaLarga->marca }} {{ $elemento->armaLarga->modelo }}
                            </div>
                            @if ($elemento->matricula_larga && strtoupper(trim((string)$elemento->matricula_larga)) !== strtoupper(trim((string)$elemento->armaLarga->matricula)))
                                <div class="text-[9px] text-amber-700 font-mono truncate" title="Matrícula solicitada: {{ $elemento->matricula_larga }}">
                                    Sol: {{ $elemento->matricula_larga }}
                                </div>
                            @endif
                        @elseif ($elemento->matricula_larga)
                            <div class="font-mono text-xs font-semibold text-slate-700 truncate" title="{{ $elemento->matricula_larga }}">
                                {{ $elemento->matricula_larga }}
                            </div>
                            <span class="inline-flex items-center rounded bg-amber-50 px-1 py-0.2 text-[9px] font-medium text-amber-800 border border-amber-200 mt-0.5"
                                  title="Matrícula solicitada no vinculada al catálogo oficial">
                                No vinculada
                            </span>
                        @else
                            <span class="text-slate-400 italic text-[10px]">Sin arma</span>
                        @endif
                    </td>

                    {{-- 7. Prefiltro / Motivos (90 px) --}}
                    <td class="w-[90px] px-2 py-2 text-center whitespace-nowrap">
                        @if ($elemento->resultado_prefiltro === 'Apto')
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10.5px] font-bold bg-emerald-50 text-emerald-800 border border-emerald-200 shadow-2xs"
                                  title="Validación algorítmica de prefiltro favorable">
                                <svg class="h-2.5 w-2.5 text-emerald-600 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                </svg>
                                <span>✓ Apto</span>
                            </span>
                        @elseif ($elemento->resultado_prefiltro === 'Descartado')
                            @php
                                $codes = is_array($elemento->codigos_descarte) ? $elemento->codigos_descarte : [];
                                $reasons = is_array($elemento->motivos_descarte) ? $elemento->motivos_descarte : [];
                                $count = count($codes) > 0 ? count($codes) : count($reasons);
                                if ($count === 0 && !empty($elemento->motivo_rechazo)) {
                                    $count = 1;
                                }
                            @endphp
                            <div class="inline-flex flex-col items-center">
                                <span class="inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-[10px] font-bold bg-rose-50 text-rose-800 border border-rose-200 shadow-2xs">
                                    <svg class="h-2.5 w-2.5 text-rose-600 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                    </svg>
                                    <span>✕ Descartado</span>
                                </span>

                                {{-- Popover interactivo bajo demanda --}}
                                <div class="relative mt-1" x-data="{ open: false }">
                                    <button type="button"
                                            @click="open = !open"
                                            @keydown.escape.window="open = false"
                                            class="inline-flex items-center gap-0.5 text-[9.5px] font-semibold text-rose-700 hover:text-rose-900 underline underline-offset-1 transition"
                                            title="Consultar motivos de descarte completos">
                                        <span>Ver motivos{{ $count > 1 ? " ({$count})" : '' }}</span>
                                        <svg class="h-2.5 w-2.5 transition-transform" :class="open ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                        </svg>
                                    </button>

                                    <div x-show="open"
                                         @click.outside="open = false"
                                         x-cloak
                                         class="absolute right-0 z-50 mt-1 w-72 rounded-lg border border-slate-200 bg-white p-3 shadow-xl text-left text-xs whitespace-normal">
                                        <div class="flex items-center justify-between pb-1.5 border-b border-slate-100 mb-2">
                                            <span class="font-bold text-slate-800 text-[11px]">Causales de descarte ({{ $count }})</span>
                                            <button type="button" @click="open = false" class="text-slate-400 hover:text-slate-600 font-bold text-sm leading-none">×</button>
                                        </div>
                                        <ul class="space-y-1.5">
                                            @if (count($codes) > 0)
                                                @foreach ($codes as $code)
                                                    @php
                                                        $meta = \App\Support\MotivoDescarte::catalog()[$code] ?? null;
                                                    @endphp
                                                    <li class="rounded bg-rose-50/60 p-1.5 border border-rose-100">
                                                        <div class="font-mono text-[10px] font-bold text-rose-800">
                                                            [{{ $code }}]
                                                        </div>
                                                        <div class="text-[11px] font-semibold text-slate-800 mt-0.5">
                                                            {{ $meta['label'] ?? $code }}
                                                        </div>
                                                        @if (!empty($meta['description']))
                                                            <div class="text-[10px] text-slate-500 mt-0.5 leading-snug">
                                                                {{ $meta['description'] }}
                                                            </div>
                                                        @endif
                                                    </li>
                                                @endforeach
                                            @elseif (count($reasons) > 0)
                                                @foreach ($reasons as $reason)
                                                    <li class="rounded bg-amber-50/60 p-1.5 border border-amber-100 text-[11px] text-slate-800">
                                                        <span class="font-semibold text-amber-800">[HISTÓRICO]</span> {{ $reason }}
                                                    </li>
                                                @endforeach
                                            @elseif (!empty($elemento->motivo_rechazo))
                                                <li class="rounded bg-rose-50/60 p-1.5 border border-rose-100 text-[11px] text-slate-800">
                                                    {{ $elemento->motivo_rechazo }}
                                                </li>
                                            @endif
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        @else
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium bg-slate-100 text-slate-600 border border-slate-200">
                                Sin evaluar
                            </span>
                        @endif
                    </td>

                    {{-- 8. Estado Trámite (90 px) --}}
                    <td class="w-[90px] px-2 py-2 text-center whitespace-nowrap">
                        @php
                            $estado = strtolower(trim((string) $elemento->estado_tramite));
                        @endphp
                        @if ($estado === 'apto')
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 shadow-2xs"
                                  title="Validado por prefiltro, listo para trámite de emisión">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 shrink-0"></span>
                                <span>Apto</span>
                            </span>
                        @elseif ($estado === 'descartado')
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold bg-rose-50 text-rose-700 border border-rose-200 shadow-2xs"
                                  title="Elemento con causales de descarte en prefiltro">
                                <span class="h-1.5 w-1.5 rounded-full bg-rose-500 shrink-0"></span>
                                <span>Descartado</span>
                            </span>
                        @elseif ($estado === 'recibido')
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold bg-blue-50 text-blue-700 border border-blue-200 shadow-2xs"
                                  title="Elemento cargado en expediente">
                                <span class="h-1.5 w-1.5 rounded-full bg-blue-500 shrink-0"></span>
                                <span>Recibido</span>
                            </span>
                        @elseif ($estado === 'pendiente')
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold bg-amber-50 text-amber-700 border border-amber-200 shadow-2xs"
                                  title="Pendiente de evaluación">
                                <span class="h-1.5 w-1.5 rounded-full bg-amber-500 shrink-0"></span>
                                <span>Pendiente</span>
                            </span>
                        @elseif ($estado === 'rechazado')
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold bg-rose-50 text-rose-800 border border-rose-300 shadow-2xs"
                                  title="Rechazo administrativo manual">
                                <span class="h-1.5 w-1.5 rounded-full bg-rose-600 shrink-0"></span>
                                <span>Rechazado</span>
                            </span>
                        @elseif ($estado === 'retenido')
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold bg-amber-50 text-amber-800 border border-amber-300 shadow-2xs"
                                  title="Retención preventiva">
                                <span class="h-1.5 w-1.5 rounded-full bg-amber-600 shrink-0"></span>
                                <span>Retenido</span>
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium bg-slate-100 text-slate-700 border border-slate-200"
                                  title="Estado registrado: {{ $elemento->estado_tramite }}">
                                <span class="h-1.5 w-1.5 rounded-full bg-slate-400 shrink-0"></span>
                                <span>{{ ucfirst($elemento->estado_tramite ?: 'Sin estado') }}</span>
                            </span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="px-4 py-12 text-center text-sm text-tx-soft">
                        @if ($hasActiveFilters)
                            <div class="flex flex-col items-center justify-center">
                                <svg class="h-8 w-8 text-slate-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                                <p class="font-semibold text-slate-700">No se encontraron elementos que coincidan con los filtros aplicados</p>
                                <p class="text-xs text-tx-soft mt-1">Pruebe modificando los términos de búsqueda o limpie los filtros activos para visualizar todos los registros.</p>
                                <a href="{{ $clearFiltersUrl }}"
                                   data-clear-filters="{{ $clearFiltersUrl }}"
                                   class="mt-3 inline-flex items-center gap-1.5 rounded-md bg-white border border-slate-300 px-3 py-1.5 text-xs font-semibold text-inst-700 shadow-2xs hover:bg-slate-50 transition">
                                    Limpiar todos los filtros
                                </a>
                            </div>
                        @else
                            <p>No hay elementos para mostrar en esta página.</p>
                        @endif
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- ================================================= --}}
{{-- 2. VISTA SEMÁNTICA RESPONSIVE PARA MÓVIL (< md)   --}}
{{-- ================================================= --}}
<div class="block md:hidden -mx-5 -mb-5 divide-y divide-slate-100 border-t border-slate-200 bg-white">
    @forelse ($elementos as $elemento)
        @php
            $nombreOficial = $elemento->persona?->nombre;
            $nombreSolicitado = $elemento->datos_solicitados['nombre'] ?? ($elemento->datos_originales_fila['NOMBRE'] ?? null);
            $categoria = $elemento->persona?->categoria ?? ($elemento->datos_solicitados['categoria'] ?? null);
            $municipio = $elemento->persona?->municipio ?? ($elemento->datos_solicitados['municipio'] ?? null);
            $agrInfo = \App\Support\AgrupamientoInstitucional::resolver($elemento, $catalogo ?? null);
            $estado = strtolower(trim((string) $elemento->estado_tramite));
            $codes = is_array($elemento->codigos_descarte) ? $elemento->codigos_descarte : [];
            $reasons = is_array($elemento->motivos_descarte) ? $elemento->motivos_descarte : [];
            $count = count($codes) > 0 ? count($codes) : count($reasons);
            if ($count === 0 && !empty($elemento->motivo_rechazo)) {
                $count = 1;
            }
        @endphp
        <div class="p-3.5 space-y-2">
            {{-- Encabezado móvil: Consecutivo, CUIP y Prefiltro --}}
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <span class="font-mono text-xs font-bold text-slate-800">
                        #{{ $elementos instanceof \Illuminate\Pagination\LengthAwarePaginator ? ($elementos->firstItem() + $loop->index) : $loop->iteration }}
                    </span>
                    @if ($elemento->numero_fila)
                        <span class="font-mono text-[10px] text-slate-400">F. {{ $elemento->numero_fila }}</span>
                    @endif
                    @if ($elemento->cuip && !\App\Services\SolicitudPrefiltroService::isCuipPlaceholder($elemento->cuip))
                        <span class="font-mono text-[11px] font-bold text-inst-900">{{ $elemento->cuip }}</span>
                    @else
                        <span class="italic text-slate-400 text-[10px]">Sin CUIP</span>
                    @endif
                </div>

                <div>
                    @if ($elemento->resultado_prefiltro === 'Apto')
                        <span class="inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-[10px] font-bold bg-emerald-50 text-emerald-800 border border-emerald-200">
                            ✓ Apto
                        </span>
                    @elseif ($elemento->resultado_prefiltro === 'Descartado')
                        <span class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-bold bg-rose-50 text-rose-800 border border-rose-200">
                            ✕ Descartado
                        </span>
                    @else
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium bg-slate-100 text-slate-600 border border-slate-200">
                            Sin evaluar
                        </span>
                    @endif
                </div>
            </div>

            {{-- Persona y Agrupamiento móvil --}}
            <div>
                <div class="font-bold text-slate-900 text-xs">
                    {{ $nombreOficial ?: ($nombreSolicitado ?: '—') }}
                </div>
                @if ($categoria || $municipio)
                    <div class="text-[10px] text-slate-500">
                        {{ $categoria ?? '' }} {{ ($categoria && $municipio) ? '· ' : '' }} {{ $municipio ?? '' }}
                    </div>
                @endif
                <div class="mt-1">
                    @if ($agrInfo['estado'] === 'verificado')
                        <span class="inline-flex items-center gap-1 rounded bg-inst-50 px-1.5 py-0.5 text-[10px] font-medium text-inst-900 border border-inst-200">
                            <span class="font-mono font-bold text-inst-700">[{{ $agrInfo['clave'] }}]</span>
                            <span>{{ $agrInfo['denominacion'] }}</span>
                        </span>
                    @else
                        <span class="text-[10px] text-slate-600 italic">
                            {{ $agrInfo['solicitado'] ?: ($agrInfo['persona'] ?: 'Sin agrupamiento oficial') }}
                        </span>
                    @endif
                </div>
            </div>

            {{-- Armamento móvil --}}
            <div class="grid grid-cols-2 gap-2 text-[10px] bg-slate-50/70 p-2 rounded border border-slate-200/80">
                <div>
                    <span class="font-semibold text-slate-500 uppercase tracking-wider text-[9px] block">Corta</span>
                    @if ($elemento->armaCorta)
                        <div class="font-mono font-bold text-slate-800">{{ $elemento->armaCorta->matricula }}</div>
                        <div class="text-slate-500 truncate">{{ $elemento->armaCorta->marca }} {{ $elemento->armaCorta->modelo }}</div>
                    @elseif ($elemento->matricula_corta)
                        <div class="font-mono font-semibold text-slate-700">{{ $elemento->matricula_corta }}</div>
                        <span class="text-amber-700 text-[9px]">No vinculada</span>
                    @else
                        <span class="text-slate-400 italic">Sin arma</span>
                    @endif
                </div>
                <div>
                    <span class="font-semibold text-slate-500 uppercase tracking-wider text-[9px] block">Larga</span>
                    @if ($elemento->armaLarga)
                        <div class="font-mono font-bold text-slate-800">{{ $elemento->armaLarga->matricula }}</div>
                        <div class="text-slate-500 truncate">{{ $elemento->armaLarga->marca }} {{ $elemento->armaLarga->modelo }}</div>
                    @elseif ($elemento->matricula_larga)
                        <div class="font-mono font-semibold text-slate-700">{{ $elemento->matricula_larga }}</div>
                        <span class="text-amber-700 text-[9px]">No vinculada</span>
                    @else
                        <span class="text-slate-400 italic">Sin arma</span>
                    @endif
                </div>
            </div>

            {{-- Pie móvil: Estado del Trámite y Botón de Motivos --}}
            <div class="flex items-center justify-between pt-1">
                <div>
                    @if ($estado === 'apto')
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> Apto
                        </span>
                    @elseif ($estado === 'descartado')
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold bg-rose-50 text-rose-700 border border-rose-200">
                            <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span> Descartado
                        </span>
                    @elseif ($estado === 'recibido')
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold bg-blue-50 text-blue-700 border border-blue-200">
                            <span class="h-1.5 w-1.5 rounded-full bg-blue-500"></span> Recibido
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium bg-slate-100 text-slate-700 border border-slate-200">
                            <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span> {{ ucfirst($elemento->estado_tramite ?: 'Sin estado') }}
                        </span>
                    @endif
                </div>

                @if ($elemento->resultado_prefiltro === 'Descartado')
                    <div x-data="{ openMobile: false }">
                        <button type="button"
                                @click="openMobile = !openMobile"
                                class="inline-flex items-center gap-1 text-[10px] font-semibold text-rose-700 underline">
                            <span>Ver motivos{{ $count > 1 ? " ({$count})" : '' }}</span>
                        </button>

                        {{-- Modal flotante móvil --}}
                        <div x-show="openMobile"
                             x-cloak
                             class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-xs">
                            <div @click.outside="openMobile = false"
                                 class="w-full max-w-sm rounded-lg bg-white p-4 shadow-xl text-left">
                                <div class="flex items-center justify-between pb-2 border-b border-slate-100 mb-3">
                                    <h4 class="font-bold text-slate-800 text-xs">Causales de descarte ({{ $count }})</h4>
                                    <button type="button" @click="openMobile = false" class="text-slate-400 text-lg leading-none font-bold">×</button>
                                </div>
                                <ul class="space-y-2 max-h-60 overflow-y-auto">
                                    @if (count($codes) > 0)
                                        @foreach ($codes as $code)
                                            @php
                                                $meta = \App\Support\MotivoDescarte::catalog()[$code] ?? null;
                                            @endphp
                                            <li class="rounded bg-rose-50/70 p-2 border border-rose-100">
                                                <div class="font-mono text-[10px] font-bold text-rose-800">[{{ $code }}]</div>
                                                <div class="text-xs font-semibold text-slate-800">{{ $meta['label'] ?? $code }}</div>
                                                @if (!empty($meta['description']))
                                                    <div class="text-[10px] text-slate-500 mt-0.5">{{ $meta['description'] }}</div>
                                                @endif
                                            </li>
                                        @endforeach
                                    @elseif (count($reasons) > 0)
                                        @foreach ($reasons as $reason)
                                            <li class="rounded bg-amber-50 p-2 border border-amber-100 text-xs text-slate-800">
                                                <span class="font-bold text-amber-800">[HISTÓRICO]</span> {{ $reason }}
                                            </li>
                                        @endforeach
                                    @endif
                                </ul>
                                <button type="button" @click="openMobile = false" class="mt-3 w-full rounded bg-slate-100 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-200">
                                    Cerrar
                                </button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @empty
        <div class="p-8 text-center text-xs text-tx-soft">
            <p class="font-semibold text-slate-700">No se encontraron elementos coincidentes</p>
            @if ($hasActiveFilters)
                <a href="{{ $clearFiltersUrl }}" data-clear-filters="{{ $clearFiltersUrl }}" class="mt-2 inline-block font-semibold text-inst-700 underline">
                    Limpiar filtros
                </a>
            @endif
        </div>
    @endforelse
</div>

{{-- ========================================== --}}
{{-- 3. PAGINACIÓN INTEGRADA AL PIE             --}}
{{-- ========================================== --}}
@if ($elementos instanceof \Illuminate\Pagination\LengthAwarePaginator && $elementos->hasPages())
    <div class="-mx-5 -mb-5 border-t border-slate-100 bg-slate-50/50 px-4 py-2.5 rounded-b-lg">
        {{ $elementos->links() }}
    </div>
@endif

