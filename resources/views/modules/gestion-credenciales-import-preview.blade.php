@extends('layouts.app')

@section('title', 'Vista previa de vigencias LOC')
@section('heading', 'Gestión de información')
@section('subheading', 'Base de credenciales · Validación previa')

@section('content')
<div>
    @php
        $statusLabels = [
            'pendiente' => 'Pendiente',
            'procesando' => 'Procesando',
            'en_revision' => 'En revisión',
            'fallido' => 'Error de análisis',
            'fallido_parcial' => 'Análisis parcial',
            'completado' => 'Terminado',
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
        $structuralErrors = $structural_errors_count ?? (($counts['conflicto'] ?? 0) + ($counts['rechazado'] ?? 0));
    @endphp

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wider text-tx-soft">Gestión de información / Base de credenciales</p>
            <div class="mt-1 flex flex-wrap items-center gap-3">
                <h1 class="text-xl font-bold tracking-tight text-inst-900">Validación previa de vigencias</h1>
                <x-status-badge id="credentials-import-status-badge" :label="$statusLabel" :tone="$statusTone"/>
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-tx-soft">
                <span class="font-medium text-tx">{{ $batch->nombre_archivo }}</span>
                <span>·</span>
                <span>Lote #<strong class="font-semibold text-inst-900">{{ $batch->id }}</strong></span>
                <span>·</span>
                <span>Fecha: {{ $batch->fecha_procesamiento?->format('d/m/Y H:i') ?? '—' }}</span>
                <span>·</span>
                <span>Hoja: {{ $batch->hoja_procesada ?: 'Sheet0' }}</span>
                <span>·</span>
                <span>Columnas: {{ number_format($batch->total_columnas ?: 40) }}</span>
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
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
            <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

        @if (in_array($batch->estado, ['pendiente', 'procesando'], true))
        <x-import-progress-monitor
            :batch="$batch"
            :progress-url="route('gestion.vigencias.importaciones.progreso', $batch)"
            :preview-url="route('gestion.vigencias.importaciones.preview', $batch)"
            card-title="Estado del análisis"
            card-description="El archivo se está leyendo y clasificando en bloques."
            pending-title="Análisis pendiente en cola"
            processing-title="Análisis en proceso"
            :initial-estimated-rows="$summary['filas_estimadas'] ?? null"
        />
    @else
        <!-- Métricas resumidas del lote -->
        <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-{{ ($counts['omitido'] ?? 0) > 0 ? '7' : '6' }}">
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-card">
                <p class="text-xs font-medium text-tx-soft">Filas analizadas</p>
                <p class="mt-1 text-2xl font-semibold text-inst-900">{{ number_format($batch->total_leidas) }}</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-card">
                <p class="text-xs font-medium text-tx-soft">Nuevas conciliadas</p>
                <p class="mt-1 text-2xl font-semibold text-emerald-700">{{ number_format($counts['nuevo_conciliado'] ?? 0) }}</p>
                <p class="mt-1 text-[11px] text-tx-soft">Enlace con personal LOC</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-card">
                <p class="text-xs font-medium text-tx-soft">Sin enlace personal</p>
                <p class="mt-1 text-2xl font-semibold text-blue-700">{{ number_format($counts['nuevo_sin_enlace'] ?? 0) }}</p>
                <p class="mt-1 text-[11px] text-tx-soft">Histórico (persona_id = NULL)</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-card">
                <p class="text-xs font-medium text-tx-soft">Cancelaciones</p>
                <p class="mt-1 text-2xl font-semibold text-amber-700">{{ number_format($counts['cancelacion'] ?? 0) }}</p>
                <p class="mt-1 text-[11px] text-tx-soft">Revisión de trámite requerida</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-card">
                <p class="text-xs font-medium text-tx-soft">Sin cambios / Act.</p>
                <p class="mt-1 text-2xl font-semibold text-slate-700">{{ number_format(($counts['sin_cambios'] ?? 0) + ($counts['actualizar'] ?? 0)) }}</p>
                <p class="mt-1 text-[11px] text-tx-soft">Preexistentes en base</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-card">
                <p class="text-xs font-medium text-tx-soft">Errores estructurales</p>
                <p class="mt-1 text-2xl font-semibold text-estado-error">{{ number_format($structuralErrors) }}</p>
                <p class="mt-1 text-[11px] text-tx-soft">{{ $structuralErrors > 0 ? 'Bloquean confirmación' : 'Sin bloqueos' }}</p>
            </div>
            @if (($counts['omitido'] ?? 0) > 0)
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-card">
                    <p class="text-xs font-medium text-tx-soft">Omitidas formalmente</p>
                    <p class="mt-1 text-2xl font-semibold text-purple-700">{{ number_format($counts['omitido']) }}</p>
                    <p class="mt-1 text-[11px] text-tx-soft">Excluidas de inserción registral</p>
                </div>
            @endif
        </div>

        <!-- Confirmación o Advertencia -->
        @if ($batch->estado === 'en_revision')
            <x-card class="mb-5" title="Confirmación de la importación" description="Revisa el resultado antes de persistir las vigencias en la base oficial.">
                @if ($structuralErrors > 0)
                    <div class="mb-4 rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-950">
                        <p class="font-medium">El lote contiene {{ number_format($structuralErrors) }} fila(s) con errores estructurales o conflictos de integridad (ej. estatus no reconocidos, fechas corruptas o discrepancias de RFC).</p>
                        <p class="mt-1 text-xs">Por política estricta de seguridad, no se puede confirmar la importación mientras existan errores estructurales en el archivo.</p>
                    </div>
                @else
                    <form method="POST" action="{{ route('gestion.vigencias.importaciones.confirmar', $batch) }}" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        @csrf
                        <div class="text-sm text-tx-soft">
                            <p class="font-medium text-slate-800">Todas las filas son estructuralmente válidas.</p>
                            <p class="text-xs text-tx-soft">Al confirmar, se insertarán las credenciales en la base de datos oficial. Los registros sin correspondencia en Personal o Armamento se conservarán con sus datos originales e impedirán autorizar trámites indebidos hasta su conciliación.</p>
                        </div>
                        <button type="submit" class="shrink-0 rounded-md bg-inst-700 px-4 py-2 text-sm font-medium text-white hover:bg-inst-900">
                            Confirmar importación
                        </button>
                    </form>
                @endif
            </x-card>
        @endif

        <!-- Filtros y Tabla de Vista Previa -->
        <div x-data="importPreviewManager({ url: '{{ route('gestion.vigencias.importaciones.preview', $batch) }}' })">
            <x-card title="Detalle de filas en staging" description="Filas analizadas con conciliación de personal, armamento y efecto en trámites.">
                {{-- Banner de error accesible en caso de fallo AJAX --}}
                <div x-show="errorMessage"
                     x-cloak
                     class="mb-3 flex items-center justify-between rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-800 shadow-2xs">
                    <span x-text="errorMessage"></span>
                    <button type="button" @click="errorMessage = null" class="font-bold text-red-400 hover:text-red-600">&times;</button>
                </div>

                <form x-ref="filterForm"
                      @submit.prevent="submitForm"
                      method="GET"
                      action="{{ route('gestion.vigencias.importaciones.preview', $batch) }}"
                      class="mb-4 grid grid-cols-1 gap-3 rounded-lg bg-slate-50 p-4 sm:grid-cols-4">
                    <label class="text-xs font-medium text-slate-600">Buscar
                        <input type="text" name="buscar" value="{{ $search }}" placeholder="CUIP o Matrícula" class="mt-1 block w-full rounded-md border-slate-300 text-sm">
                    </label>
                    <label class="text-xs font-medium text-slate-600">Filtro de clasificación
                        <select name="accion" class="mt-1 block w-full rounded-md border-slate-300 text-sm">
                            <option value="">Todas las filas</option>
                            <option value="nuevo_conciliado" @selected($action_filter === 'nuevo_conciliado')>Nuevas conciliadas con Personal</option>
                            <option value="nuevo_sin_enlace" @selected($action_filter === 'nuevo_sin_enlace')>Históricas sin enlace personal</option>
                            <option value="cancelacion" @selected($action_filter === 'cancelacion')>Estatus Cancelación</option>
                            @if (($counts['omitido'] ?? 0) > 0)
                                <option value="omitido" @selected($action_filter === 'omitido')>Filas omitidas / excluidas ({{ number_format($counts['omitido']) }})</option>
                            @endif
                            <option value="actualizar" @selected($action_filter === 'actualizar')>Actualizaciones</option>
                            <option value="sin_cambios" @selected($action_filter === 'sin_cambios')>Sin cambios</option>
                            <option value="conflicto" @selected($action_filter === 'conflicto')>Errores estructurales / Conflictos</option>
                            <option value="rechazado" @selected($action_filter === 'rechazado')>Rechazadas</option>
                        </select>
                    </label>
                    <div class="flex items-center gap-2 pt-5">
                        <label class="flex items-center gap-2 text-xs font-medium text-slate-700">
                            <input type="checkbox" name="solo_errores" value="1" @checked($only_errors) class="rounded border-slate-300 text-inst-700">
                            <span>Solo errores estructurales</span>
                        </label>
                    </div>
                    <div class="flex items-end gap-2">
                        <button type="submit" class="rounded-md bg-inst-900 px-3 py-2 text-sm font-medium text-white hover:bg-inst-700">Filtrar</button>
                        <a href="{{ route('gestion.vigencias.importaciones.preview', $batch) }}"
                           data-clear-filters="{{ route('gestion.vigencias.importaciones.preview', $batch) }}"
                           class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-100">Limpiar</a>
                        <div x-show="isLoading" x-cloak class="inline-flex items-center gap-1.5 text-xs font-medium text-inst-700 pl-2">
                            <span class="h-2 w-2 rounded-full bg-inst-600 animate-pulse"></span>
                            <span>Cargando...</span>
                        </div>
                    </div>
                </form>

                <!-- Badges interactivos tipo Chips para filtrado rápido -->
                <div x-ref="chipsContainer" @click="handleContainerClick($event)" class="mb-4 border-b border-slate-200 pb-3">
                    @include('modules.partials.gestion-vigencias-chips')
                </div>

                <!-- Tabla de datos con paginación integrada -->
                <div x-ref="tableContainer"
                     @click="handleContainerClick($event)"
                     class="relative transition-opacity duration-150"
                     :class="isLoading ? 'opacity-50 pointer-events-none' : ''">
                    @include('modules.partials.gestion-vigencias-table')
                </div>
            </x-card>
        </div>
    @endif
</div>

@include('modules.partials.import-preview-ajax')
@endsection
