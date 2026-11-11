@extends('layouts.app')

@section('title', 'Vista previa de importación LOC')
@section('heading', 'Gestión de información')
@section('subheading', 'Personal autorizado LOC · Validación previa')

@section('content')
<div>
    @php
        $statusLabels = [
            'pendiente' => 'Pendiente',
            'procesando' => 'Procesando',
            'en_revision' => 'En revisión',
            'fallido' => 'Error de análisis',
            'fallido_parcial' => 'Análisis parcial',
            'completado' => 'Completado',
        ];
        $statusTones = [
            'pendiente' => 'info',
            'procesando' => 'info',
            'en_revision' => 'warning',
            'fallido' => 'danger',
            'fallido_parcial' => 'warning',
            'completado' => 'success',
        ];
        $statusLabel = $statusLabels[$batch->estado] ?? str($batch->estado)->replace('_', ' ')->title();
        $statusTone = $statusTones[$batch->estado] ?? 'neutral';
    @endphp

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wider text-tx-soft">Gestión de información / Personal autorizado LOC</p>
            <div class="mt-1 flex flex-wrap items-center gap-3">
                <h1 class="text-xl font-bold tracking-tight text-inst-900">Validación previa de importación</h1>
                <x-status-badge id="personal-import-status-badge" :label="$statusLabel" :tone="$statusTone"/>
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-tx-soft">
                <span class="font-medium text-tx">{{ $batch->nombre_archivo }}</span>
                <span>·</span>
                <span>Lote #<strong class="font-semibold text-inst-900">{{ $batch->id }}</strong></span>
                <span>·</span>
                <span>Cargado: {{ $batch->fecha_procesamiento?->format('d/m/Y H:i') ?? '—' }}</span>
                <span>·</span>
                <span>Hoja: {{ $batch->hoja_procesada ?: '—' }}</span>
                <span>·</span>
                <span>Columnas: {{ number_format($batch->total_columnas) }}</span>
                @if ($batch->archivo_sha256)
                    <span>·</span>
                    <span title="Firma SHA-256">SHA: <code class="rounded bg-slate-100 px-1 py-0.5 font-mono text-[11px] text-slate-700">{{ substr($batch->archivo_sha256, 0, 12) }}…</code></span>
                @endif
            </div>
        </div>
        <a href="{{ route('gestion') }}" class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-inst-900 shadow-sm transition-colors hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-inst-700 focus:ring-offset-1">
            <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Volver a fuentes
        </a>
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @if (!empty($summary['limpieza']))
        @php($cleaning = $summary['limpieza'])
        <details class="group mb-5 rounded-lg border border-slate-200 bg-white p-3.5 shadow-2xs" aria-labelledby="loc-cleaning-heading">
            <summary class="flex cursor-pointer items-center justify-between font-medium text-xs text-tx hover:text-inst-900 focus:outline-none">
                <div class="flex items-center gap-2">
                    <svg class="h-4 w-4 text-inst-700 transition-transform group-open:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                    <span id="loc-cleaning-heading" class="font-semibold text-inst-900">Limpieza y normalización del archivo</span>
                    <span class="hidden sm:inline text-tx-soft">· Encabezados y valores normalizados</span>
                </div>
                <x-status-badge :label="!empty($cleaning['errores']) ? 'Requiere revisión' : 'Estructura normalizada'" :tone="!empty($cleaning['errores']) ? 'warning' : 'success'"/>
            </summary>
            <div class="mt-4 border-t border-slate-100 pt-3">
                <p class="text-xs text-tx-soft">Los encabezados se comparan sin distinguir mayúsculas, acentos ni espacios repetidos; los valores se comparan después de quitar espacios externos.</p>
                <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach ([
                        ['Encabezados duplicados', $cleaning['columnas_duplicadas_detectadas'] ?? 0],
                        ['Columnas redundantes descartadas', $cleaning['columnas_redundantes_descartadas'] ?? 0],
                        ['Filas con discrepancias', $cleaning['filas_con_discrepancias'] ?? 0],
                    ] as [$label, $value])
                        <div class="rounded-md bg-slate-50 p-2.5">
                            <dt class="text-xs text-tx-soft">{{ $label }}</dt>
                            <dd class="mt-0.5 text-lg font-semibold text-inst-900">{{ number_format((int) $value) }}</dd>
                        </div>
                    @endforeach
                    <div class="rounded-md bg-slate-50 p-2.5">
                        <dt class="text-xs text-tx-soft">Filas con errores</dt>
                        <dd class="mt-0.5 text-lg font-semibold text-inst-900">
                            <a href="{{ route('gestion.importaciones.preview', $batch) }}?solo_errores=1#excepciones" class="underline decoration-inst-300 underline-offset-4 hover:text-inst-700" aria-label="Ver {{ number_format((int) ($cleaning['filas_con_errores'] ?? $batch->total_rechazadas)) }} filas con errores">
                                {{ number_format((int) ($cleaning['filas_con_errores'] ?? $batch->total_rechazadas)) }}
                            </a>
                        </dd>
                    </div>
                </dl>
                @if (!empty($cleaning['encabezados_afectados']))
                    <div class="mt-3 border-t border-slate-100 pt-3">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-tx-soft">Encabezados afectados</h3>
                        <ul class="mt-2 space-y-1.5 text-xs text-tx">
                            @foreach ($cleaning['encabezados_afectados'] as $header)
                                <li>
                                    <strong>{{ $header['encabezado'] }}</strong>
                                    <span class="text-tx-soft">· Columnas {{ implode(', ', $header['columnas']) }}</span>
                                    @if (!empty($header['redundantes_descartadas']))
                                        <span class="text-estado-exito">· Redundantes descartadas: {{ implode(', ', $header['redundantes_descartadas']) }}</span>
                                    @endif
                                    @if (($header['filas_con_discrepancia'] ?? 0) > 0)
                                        <span class="text-estado-error">· {{ number_format($header['filas_con_discrepancia']) }} filas con diferencias</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if (!empty($cleaning['advertencias']))
                    <div class="mt-3 rounded-md border border-amber-200 bg-amber-50 p-2.5 text-xs text-amber-950">
                        <h3 class="font-semibold">Advertencias</h3>
                        <ul class="mt-1 list-inside list-disc">@foreach ($cleaning['advertencias'] as $warning)<li>{{ $warning }}</li>@endforeach</ul>
                    </div>
                @endif
                @if (!empty($cleaning['errores']))
                    <div class="mt-3 rounded-md border border-red-200 bg-red-50 p-2.5 text-xs text-red-900" role="alert">
                        <h3 class="font-semibold">Requiere intervención</h3>
                        <ul class="mt-1 list-inside list-disc">@foreach ($cleaning['errores'] as $error)<li>{{ $error }}</li>@endforeach</ul>
                        <p class="mt-1 text-[11px]">Las filas discrepantes se conservan en la vista previa, pero no pueden aplicarse hasta corregir el archivo y volver a analizarlo.</p>
                    </div>
                @endif
            </div>
        </details>
    @endif
    @if (!empty($summary['error']))
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <strong>Resultado del análisis:</strong> {{ $summary['error'] }}
            @if (!empty($summary['diagnostico']['incident_id']))
                <p class="mt-2 text-xs">Incidencia: <code>{{ $summary['diagnostico']['incident_id'] }}</code>
                    @if (!empty($summary['diagnostico']['error_code']))
                        · Código: <code>{{ $summary['diagnostico']['error_code'] }}</code>
                    @endif
                    @if (!empty($summary['diagnostico']['stage']))
                        · Etapa: <code>{{ $summary['diagnostico']['stage'] }}</code>
                    @endif
                </p>
                @php($location = $summary['diagnostico']['location'] ?? [])
                @if (!empty($location))
                    <p class="mt-1 text-xs">Ubicación:
                        @if (!empty($location['sheet'])) hoja {{ $location['sheet'] }}@endif
                        @if (!empty($location['header_row'])) · fila de encabezados {{ $location['header_row'] }}@endif
                        @if (!empty($location['row'])) · fila {{ $location['row'] }}@endif
                        @if (!empty($location['row_start'])) · filas {{ $location['row_start'] }}–{{ $location['row_end'] ?? '?' }}@endif
                    </p>
                @endif
            @endif
        </div>
    @endif
    @if (in_array($batch->estado, ['pendiente', 'procesando'], true))
        <x-import-progress-monitor
            :batch="$batch"
            :progress-url="route('gestion.importaciones.progreso', $batch)"
            :preview-url="route('gestion.importaciones.preview', $batch)"
            pending-title="Análisis en cola"
            processing-title="Analizando archivo…"
            :worker-command="$batch->tipo_fuente === 'armamento_loc'
                ? 'php artisan queue:work loc_weapons_import --queue=loc-weapons-imports --timeout=3600 --tries=1'
                : 'php artisan queue:work loc_import --queue=loc-imports --timeout=900 --tries=1'"
            :initial-estimated-rows="$summary['progreso']['filas_estimadas'] ?? null"
            :initial-stage="$summary['diagnostico']['etapa_actual'] ?? null"
        />
    @endif
    @if (!in_array($batch->estado, ['pendiente', 'procesando'], true))
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
        @foreach ([
            'nuevo' => ['Nuevos', 'text-emerald-700'],
            'actualizar' => ['Por actualizar', 'text-blue-700'],
            'sin_cambios' => ['Sin cambios', 'text-slate-700'],
            'rechazado' => ['Rechazados', 'text-red-700'],
            'conflicto' => ['CUIP duplicado', 'text-orange-700'],
            'pendiente' => ['Pendientes', 'text-amber-700'],
        ] as $key => $meta)
            <div class="rounded-lg bg-white p-4 shadow-card ring-1 ring-slate-900/5">
                <div class="text-xs font-medium text-tx-soft">{{ $meta[0] }}</div>
                <div class="mt-2 text-2xl font-semibold {{ $meta[1] }}">{{ number_format((int) ($counts[$key] ?? 0)) }}</div>
            </div>
        @endforeach
        <div class="rounded-lg bg-white p-4 shadow-card ring-1 ring-slate-900/5">
            <div class="text-xs font-medium text-tx-soft">CUIP ausente</div>
            <div class="mt-2 text-2xl font-semibold text-estado-error">{{ number_format($missingCuipCount) }}</div>
        </div>
    </div>

    @if ($blockingErrors > 0)
        <div class="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 shadow-sm" role="alert">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="rounded-full bg-red-100 p-2 text-red-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-red-900">
                            {{ number_format($blockingErrors) }} fila(s) requieren corrección o presentan conflicto
                        </h3>
                        <p class="text-xs text-red-700">
                            Las filas válidas ({{ number_format(($counts['nuevo'] ?? 0) + ($counts['actualizar'] ?? 0) + ($counts['sin_cambios'] ?? 0)) }}) pueden confirmarse; estas incidencias no se aplicarán al padrón oficial.
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('gestion.importaciones.preview', [$batch, 'accion' => 'requieren_correccion']) }}#excepciones" class="rounded-lg bg-red-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-800">
                        Revisar {{ number_format($blockingErrors) }} incidencias
                    </a>
                </div>
            </div>
        </div>
    @else
        <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm">
            <div class="flex items-center gap-3">
                <div class="rounded-full bg-emerald-100 p-2 text-emerald-700">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-emerald-900">0 incidencias bloqueantes</h3>
                    <p class="text-xs text-emerald-700">Todas las filas analizadas son válidas y el padrón está listo para confirmarse.</p>
                </div>
            </div>
        </div>
    @endif

    @if ((empty($actionFilter) || in_array($actionFilter, ['requieren_correccion', 'conflicto', 'rechazado'], true) || $onlyErrors) && $exceptionRows->total() > 0)
    <section id="excepciones" class="mt-6 scroll-mt-6 rounded-xl border border-red-200 bg-white shadow-sm" aria-labelledby="loc-exceptions-heading">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-red-100 px-5 py-4">
            <div>
                <h2 id="loc-exceptions-heading" class="font-semibold text-slate-900">Filas que requieren corrección</h2>
                <p class="mt-1 text-sm text-slate-500">
                    {{ number_format($exceptionRows->total()) }} fila(s) rechazadas o en conflicto. Las filas válidas del lote pueden confirmarse; estas excepciones no se aplicarán.
                </p>
            </div>
            <a href="{{ route('gestion.importaciones.preview', $batch) }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Ver todas las filas</a>
        </div>
        @if ($exceptionRows->count())
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-red-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Fila Excel</th>
                            <th class="px-4 py-3">CUIP</th>
                            <th class="px-4 py-3">Nombre</th>
                            <th class="px-4 py-3">ID personal</th>
                            <th class="px-4 py-3">Categoría</th>
                            <th class="px-4 py-3">Municipio</th>
                            <th class="px-4 py-3">Motivo / Acción</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($exceptionRows as $exceptionRow)
                            @php($exceptionFields = is_array($exceptionRow->datos) ? ($exceptionRow->datos['campos'] ?? []) : [])
                            @php($exceptionErrors = is_array($exceptionRow->errores) ? $exceptionRow->errores : [])
                            <tr class="align-top">
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $exceptionRow->numero_fila ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-mono text-slate-700">{{ $exceptionRow->cuip ?: ($exceptionFields['cuip'] ?? '—') }}</td>
                                <td class="min-w-48 px-4 py-3 text-slate-800">{{ $exceptionFields['nombre'] ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700">{{ $exceptionFields['personal_id'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-slate-700">{{ $exceptionFields['categoria'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-slate-700">{{ $exceptionFields['municipio'] ?? '—' }}</td>
                                <td class="min-w-64 px-4 py-3 text-red-800">
                                    @if ($exceptionErrors)
                                        <ul class="list-inside list-disc">
                                            @foreach ($exceptionErrors as $exceptionError)
                                                <li>{{ is_scalar($exceptionError) ? $exceptionError : '—' }}</li>
                                            @endforeach
                                        </ul>
                                    @elseif ($exceptionRow->accion === 'conflicto')
                                        CUIP duplicado en el archivo; todas las filas coincidentes quedan excluidas.
                                    @else
                                        —
                                    @endif

                                    @if (in_array($batch->estado, ['en_revision', 'pendiente'], true) && $exceptionRow->accion !== 'omitido')
                                        <details class="mt-2 text-xs">
                                            <summary class="cursor-pointer font-medium text-slate-700 hover:text-slate-900 hover:underline">Omitir formalmente con trazabilidad</summary>
                                            <form method="POST" action="{{ route('gestion.importaciones.omitir', [$batch, $exceptionRow]) }}" class="mt-2 space-y-2 rounded bg-slate-50 p-2">
                                                @csrf
                                                <input type="text" name="motivo" placeholder="Motivo de exclusión..." required minlength="5" class="w-full rounded border border-slate-300 px-2 py-1 text-xs text-slate-800">
                                                <button type="submit" class="rounded bg-slate-700 px-2 py-1 text-xs font-medium text-white hover:bg-slate-900">Confirmar exclusión</button>
                                            </form>
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 px-4 py-3">{{ $exceptionRows->links() }}</div>
        @else
            <p class="px-5 py-6 text-sm text-slate-500">No hay filas rechazadas ni conflictos en este lote.</p>
        @endif
    </section>
    @endif

    <div class="mt-6 rounded-xl border border-slate-200 bg-white shadow-sm"
         x-data="importPreviewManager({ url: '{{ route('gestion.importaciones.preview', $batch) }}' })">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="font-semibold text-slate-900">Detalle por fila</h2>
            @if ($onlyErrors)
                <p class="mt-1 text-sm text-amber-800">Mostrando únicamente filas rechazadas o en conflicto. <a href="{{ route('gestion.importaciones.preview', $batch) }}" class="font-medium underline">Ver todas las filas</a></p>
            @endif
            <p class="mt-1 text-sm text-slate-500">{{ number_format($batch->total_leidas) }} filas leídas; {{ number_format($batch->total_aceptadas) }} válidas y {{ number_format($batch->total_rechazadas) }} rechazadas o en conflicto. Las filas rechazadas o en conflicto no se aplicarán.</p>
        </div>

        <div class="p-5">
            {{-- Banner de error accesible en caso de fallo AJAX --}}
            <div x-show="errorMessage"
                 x-cloak
                 class="mb-3 flex items-center justify-between rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-800 shadow-2xs">
                <span x-text="errorMessage"></span>
                <button type="button" @click="errorMessage = null" class="font-bold text-red-400 hover:text-red-600">&times;</button>
            </div>

            <!-- Barra de búsqueda contextual y selector -->
            <form x-ref="filterForm"
                  @submit.prevent="submitForm"
                  method="GET"
                  action="{{ route('gestion.importaciones.preview', $batch) }}"
                  class="mb-4 grid grid-cols-1 gap-3 rounded-lg bg-slate-50 p-4 sm:grid-cols-4">
                <div class="sm:col-span-2">
                    <label for="buscar" class="block text-xs font-medium text-slate-600">Buscar por CUIP, Nombre o RFC</label>
                    <input type="text" id="buscar" name="buscar" value="{{ $search }}" placeholder="Ej. CUIP, Nombre o RFC..."
                           class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-slate-500 focus:ring-slate-500">
                </div>
                <div>
                    <label for="accion" class="block text-xs font-medium text-slate-600">Acción</label>
                    <select id="accion" name="accion" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-slate-500 focus:ring-slate-500">
                        <option value="">Todas las acciones</option>
                        <option value="nuevo" @selected($actionFilter === 'nuevo')>Nuevos</option>
                        <option value="actualizar" @selected($actionFilter === 'actualizar')>Por actualizar</option>
                        <option value="sin_cambios" @selected($actionFilter === 'sin_cambios')>Sin cambios</option>
                        <option value="requieren_correccion" @selected($actionFilter === 'requieren_correccion' || $onlyErrors)>Requieren corrección</option>
                        <option value="conflicto" @selected($actionFilter === 'conflicto')>Conflictos (CUIP duplicado)</option>
                        <option value="rechazado" @selected($actionFilter === 'rechazado')>Rechazados</option>
                        @if (($counts['omitido'] ?? 0) > 0)
                            <option value="omitido" @selected($actionFilter === 'omitido')>Omitidos formalmente</option>
                        @endif
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                        Buscar
                    </button>
                    <a href="{{ route('gestion.importaciones.preview', $batch) }}"
                       data-clear-filters="{{ route('gestion.importaciones.preview', $batch) }}"
                       class="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-100">
                        Limpiar
                    </a>
                    <div x-show="isLoading" x-cloak class="inline-flex items-center gap-1.5 text-xs font-medium text-inst-700 pl-2">
                        <span class="h-2 w-2 rounded-full bg-inst-600 animate-pulse"></span>
                        <span>Cargando...</span>
                    </div>
                </div>
            </form>

            <!-- Badges interactivos tipo Chips para filtrado rápido -->
            <div x-ref="chipsContainer" @click="handleContainerClick($event)" class="mb-4 border-b border-slate-200 pb-3">
                @include('modules.partials.gestion-personal-chips')
            </div>

            <!-- Tabla de datos con paginación integrada -->
            <div x-ref="tableContainer"
                 @click="handleContainerClick($event)"
                 class="relative transition-opacity duration-150"
                 :class="isLoading ? 'opacity-50 pointer-events-none' : ''">
                @include('modules.partials.gestion-personal-table')
            </div>
        </div>
    </div>

    <div class="mt-6 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
        <p class="max-w-3xl text-sm text-amber-900">
            @if ($batch->estado === 'fallido_parcial')
                La ejecución anterior se detuvo: {{ number_format($applicationCounts['insertado'] ?? 0) }} insertadas, {{ number_format($applicationCounts['actualizado'] ?? 0) }} actualizadas y {{ number_format($applicationCounts['sin_cambios'] ?? 0) }} sin cambios. Puedes reanudarla; los bloques confirmados se conservan y las filas pendientes se revalidan por CUIP.
            @elseif ($batch->estado === 'completado')
                Resultado persistido: {{ number_format($batch->total_insertadas) }} insertadas, {{ number_format($batch->total_actualizadas) }} actualizadas y {{ number_format($batch->total_sin_cambios) }} sin cambios.
            @else
                La confirmación aplica filas válidas en bloques transaccionales. Los conflictos y las filas rechazadas se conservan en el historial sin aplicarse.
            @endif
        </p>
        <form method="POST" action="{{ route('gestion.importaciones.confirmar', $batch) }}" onsubmit="return confirm('¿Confirmas aplicar los registros válidos de esta importación?');">
            @csrf
            <button type="submit" @disabled(!in_array($batch->estado, ['en_revision', 'fallido_parcial'], true) || (($counts['nuevo'] ?? 0) + ($counts['actualizar'] ?? 0) + ($counts['sin_cambios'] ?? 0) === 0)) class="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-40">
                {{ $batch->estado === 'fallido_parcial' ? 'Reanudar importación' : 'Confirmar importación' }}
            </button>
        </form>
    </div>
    @endif
</div>

@include('modules.partials.import-preview-ajax')
@endsection
