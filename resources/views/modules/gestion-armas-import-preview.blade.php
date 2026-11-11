@extends('layouts.app')

@section('title', 'Vista previa de armamento LOC')
@section('heading', 'Gestión de información')
@section('subheading', 'Armamento LOC · Validación previa')

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
        $estimatedRows = $summary['progreso']['filas_estimadas'] ?? null;
    @endphp

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wider text-tx-soft">Gestión de información / Armamento LOC</p>
            <div class="mt-1 flex flex-wrap items-center gap-3">
                <h1 class="text-xl font-bold tracking-tight text-inst-900">Validación previa de inventario</h1>
                <x-status-badge id="weapons-import-status-badge" :label="$statusLabel" :tone="$statusTone"/>
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-tx-soft">
                <span class="font-medium text-tx">{{ $batch->nombre_archivo }}</span>
                <span>·</span>
                <span>Lote #<strong class="font-semibold text-inst-900">{{ $batch->id }}</strong></span>
                <span>·</span>
                <span>Fecha: {{ $batch->fecha_procesamiento?->format('d/m/Y H:i') ?? '—' }}</span>
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
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
            <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @if (!empty($summary['error']))
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
            {{ $summary['error'] }}
            @if (!empty($summary['diagnostico']['incident_id']))
                <span class="mt-1 block text-xs">Incidencia: {{ $summary['diagnostico']['incident_id'] }}</span>
            @endif
        </div>
    @elseif (in_array($batch->estado, ['fallido', 'fallido_parcial'], true))
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
            {{ $batch->estado === 'fallido' ? 'No fue posible completar el análisis del archivo.' : 'El análisis se interrumpió después de leer algunas filas. Revisa los datos disponibles y el diagnóstico del lote antes de volver a intentarlo.' }}
        </div>
    @endif

    @if (in_array($batch->estado, ['pendiente', 'procesando'], true))
        <x-import-progress-monitor
            :batch="$batch"
            :progress-url="route('gestion.armamento.importaciones.progreso', $batch)"
            :preview-url="route('gestion.armamento.importaciones.preview', $batch)"
            card-title="Estado del análisis"
            card-description="Los datos se actualizan desde el lote. El análisis no modifica el inventario."
            pending-title="Análisis pendiente en cola"
            processing-title="Análisis en proceso"
            worker-command="php artisan queue:work loc_weapons_import --queue=loc-weapons-imports --timeout=3600 --tries=1"
            :initial-estimated-rows="$estimatedRows"
            :initial-stage="$summary['diagnostico']['etapa_actual'] ?? null"
            status-badge-id="weapons-import-status-badge"
        />
    @else
        @php
            $rejected = (int) ($counts['rechazado'] ?? 0) + (int) ($counts['conflicto'] ?? 0);
        @endphp
        <section class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-6" aria-label="Resultados reales del análisis">
            <x-card title="Filas leídas">
                <p class="text-2xl font-semibold text-inst-900">{{ number_format((int) $batch->total_leidas) }}</p>
            </x-card>
            <x-card title="Candidatas nuevas">
                <p class="text-2xl font-semibold text-inst-900">{{ number_format((int) ($counts['nuevo'] ?? 0)) }}</p>
            </x-card>
            <x-card title="Actualizaciones">
                <p class="text-2xl font-semibold text-inst-700">{{ number_format((int) ($counts['actualizar'] ?? 0)) }}</p>
            </x-card>
            <x-card title="Conflictos">
                <p class="text-2xl font-semibold text-estado-adv">{{ number_format((int) ($counts['conflicto'] ?? 0)) }}</p>
            </x-card>
            <x-card title="Errores de datos">
                <p class="text-2xl font-semibold text-estado-error">{{ number_format((int) ($counts['rechazado'] ?? 0)) }}</p>
            </x-card>
            <x-card title="Sin cambios">
                <p class="text-2xl font-semibold text-tx-soft">{{ number_format((int) ($counts['sin_cambios'] ?? 0)) }}</p>
            </x-card>
            @if ((int) ($counts['omitido'] ?? 0) > 0)
                <x-card title="Omitidas">
                    <p class="text-2xl font-semibold text-tx-soft">{{ number_format((int) $counts['omitido']) }}</p>
                </x-card>
            @endif
        </section>

        <section id="excepciones" class="mt-6 scroll-mt-6"
                 x-data="importPreviewManager({ url: '{{ route('gestion.armamento.importaciones.preview', $batch) }}' })">
            <x-card title="Filas analizadas del inventario" :description="number_format((int) ($counts['rechazado'] ?? 0) + (int) ($counts['conflicto'] ?? 0)).' fila(s) rechazadas o en conflicto. El ID_ARMAMENTO es una referencia externa; la matrícula es la identidad del arma.'">
                
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
                      action="{{ route('gestion.armamento.importaciones.preview', $batch) }}"
                      class="mb-4 grid grid-cols-1 gap-3 rounded-lg bg-slate-50 p-4 sm:grid-cols-4">
                    <div class="sm:col-span-2">
                        <label for="buscar" class="block text-xs font-medium text-slate-600">Buscar por Matrícula, Marca o Modelo</label>
                        <input type="text" id="buscar" name="buscar" value="{{ $search ?? '' }}" placeholder="Ej. BERETTA, GBL031, 2041..."
                               class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-inst-700 focus:ring-inst-700">
                    </div>
                    <div>
                        <label for="accion" class="block text-xs font-medium text-slate-600">Acción</label>
                        <select id="accion" name="accion" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm focus:border-inst-700 focus:ring-inst-700">
                            <option value="">Todas las acciones</option>
                            <option value="nuevo" @selected(($actionFilter ?? '') === 'nuevo')>Candidatas nuevas</option>
                            <option value="actualizar" @selected(($actionFilter ?? '') === 'actualizar')>Actualizaciones</option>
                            <option value="sin_cambios" @selected(($actionFilter ?? '') === 'sin_cambios')>Sin cambios</option>
                            <option value="requieren_correccion" @selected(($actionFilter ?? '') === 'requieren_correccion' || $onlyErrors)>Conflictos / Errores</option>
                            <option value="conflicto" @selected(($actionFilter ?? '') === 'conflicto')>Conflictos</option>
                            <option value="rechazado" @selected(($actionFilter ?? '') === 'rechazado')>Rechazados</option>
                            @if ((int) ($counts['omitido'] ?? 0) > 0)
                                <option value="omitido" @selected(($actionFilter ?? '') === 'omitido')>Omitidos formalmente</option>
                            @endif
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <button type="submit" class="rounded-md bg-inst-900 px-4 py-2 text-sm font-medium text-white hover:bg-inst-700">
                            Buscar
                        </button>
                        <a href="{{ route('gestion.armamento.importaciones.preview', $batch) }}"
                           data-clear-filters="{{ route('gestion.armamento.importaciones.preview', $batch) }}"
                           class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-100">
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
                    @include('modules.partials.gestion-armas-chips')
                </div>

                <!-- Tabla de datos con paginación integrada -->
                <div x-ref="tableContainer"
                     @click="handleContainerClick($event)"
                     class="relative transition-opacity duration-150"
                     :class="isLoading ? 'opacity-50 pointer-events-none' : ''">
                    @include('modules.partials.gestion-armas-table')
                </div>
            </x-card>
        </section>

        @if ($batch->estado === 'en_revision' && $rejected === 0)
            @if ($canConfirm && ! auth()->user()?->getAttribute('local_demo_access'))
                <form method="POST" action="{{ route('gestion.armamento.importaciones.confirmar', $batch) }}" class="mt-6 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
                    @csrf
                    <p class="max-w-3xl text-sm text-amber-900">La confirmación inserta las armas nuevas y actualiza los registros operativos existentes con información modificada desde la fuente oficial. Los registros sin cambios se conservan intactos.</p>
                    <button type="submit" class="rounded-md bg-inst-700 px-4 py-2 text-sm font-medium text-white hover:bg-inst-900">Confirmar altas y actualizaciones</button>
                </form>
            @else
                <p class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Sólo el responsable del lote o un superadministrador puede confirmar las altas y actualizaciones.</p>
            @endif
        @elseif ($rejected > 0 && $batch->estado === 'en_revision')
            <p class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">El lote contiene excepciones. No se puede confirmar ninguna fila; corrige el archivo o resuelve las excepciones antes de continuar.</p>
        @elseif ($batch->estado === 'completado')
            <p class="mt-6 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">Lote completado: {{ number_format($batch->total_insertadas) }} armas nuevas, {{ number_format($batch->total_actualizadas) }} actualizadas, {{ number_format($batch->total_sin_cambios) }} sin cambios.</p>
        @endif
    @endif
</div>

@include('modules.partials.import-preview-ajax')
@endsection
