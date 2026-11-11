@extends('layouts.app')

@section('title', 'Vista previa de catálogo organizacional')
@section('heading', 'Gestión de información')
@section('subheading', 'Catálogo organizacional · Validación previa y versionado')

@section('content')
<div>
    @php
        $statusLabels = [
            'pendiente' => 'Pendiente',
            'procesando' => 'Procesando',
            'en_revision' => 'En revisión',
            'fallido' => 'Error de análisis',
            'completado' => 'Publicado',
        ];
        $statusTones = [
            'pendiente' => 'info',
            'procesando' => 'info',
            'en_revision' => 'warning',
            'fallido' => 'danger',
            'completado' => 'success',
        ];
        $statusLabel = $statusLabels[$batch->estado] ?? str($batch->estado)->replace('_', ' ')->title();
        $statusTone = $statusTones[$batch->estado] ?? 'neutral';
        $blockingErrors = $blocking_errors ?? (($counts['conflicto'] ?? 0) + ($counts['rechazado'] ?? 0));
    @endphp

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wider text-tx-soft">Gestión de información / Catálogo organizacional</p>
            <div class="mt-1 flex flex-wrap items-center gap-3">
                <h1 class="text-xl font-bold tracking-tight text-inst-900">Validación previa del catálogo</h1>
                <x-status-badge id="catalogo-import-status-badge" :label="$statusLabel" :tone="$statusTone"/>
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-tx-soft">
                <span class="font-medium text-tx">{{ $batch->nombre_archivo }}</span>
                <span>·</span>
                <span>Lote #<strong class="font-semibold text-inst-900">{{ $batch->id }}</strong></span>
                <span>·</span>
                <span>Fecha: {{ $batch->fecha_procesamiento?->format('d/m/Y H:i') ?? '—' }}</span>
                @if ($batch->archivo_sha256)
                    <span>·</span>
                    <span title="Firma SHA-256">SHA: <code class="rounded bg-slate-100 px-1 py-0.5 font-mono text-[11px] text-slate-700">{{ substr($batch->archivo_sha256, 0, 16) }}…</code></span>
                @endif
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-2">
            @if ($active_version)
                <a href="{{ route('gestion.catalogo.vigente') }}" class="inline-flex shrink-0 items-center justify-center rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-inst-900 shadow-sm transition hover:bg-slate-50">
                    Ver versión vigente (v{{ $active_version->version_numero }})
                </a>
            @endif
            <a href="{{ route('gestion') }}" class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-inst-900 shadow-sm transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-inst-700 focus:ring-offset-1">
                <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Volver a fuentes
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
            {{ session('success') }}
        </div>
    @endif
    @if (session('info'))
        <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800" role="status">
            {{ session('info') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

        @if (in_array($batch->estado, ['pendiente', 'procesando'], true))
        <x-import-progress-monitor
            :batch="$batch"
            :progress-url="route('gestion.catalogo.importaciones.progreso', $batch)"
            :preview-url="route('gestion.catalogo.importaciones.preview', $batch)"
            card-title="Estado del análisis"
            card-description="El archivo está siendo analizado y validado columna por columna."
            pending-title="Análisis en cola"
            processing-title="Procesando validación"
        />
    @else
        <!-- Tarjetas de métricas comparativas -->
        <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-card">
                <p class="text-xs text-tx-soft">Filas leídas</p>
                <p class="mt-1 text-2xl font-semibold text-inst-900">{{ number_format($batch->total_leidas) }}</p>
                <p class="mt-1 text-[11px] text-tx-soft">Registros de datos en Excel</p>
            </div>
            <div class="rounded-lg border border-emerald-200 bg-emerald-50/50 p-4 shadow-card">
                <p class="text-xs text-emerald-800">Nuevas claves</p>
                <p class="mt-1 text-2xl font-semibold text-emerald-900">{{ number_format($counts['nuevo'] ?? 0) }}</p>
                <p class="mt-1 text-[11px] text-emerald-700">Ausentes en versión vigente</p>
            </div>
            <div class="rounded-lg border border-blue-200 bg-blue-50/50 p-4 shadow-card">
                <p class="text-xs text-blue-800">Actualizaciones</p>
                <p class="mt-1 text-2xl font-semibold text-blue-900">{{ number_format($counts['actualizar'] ?? 0) }}</p>
                <p class="mt-1 text-[11px] text-blue-700">Cambio de datos descriptivos</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 shadow-card">
                <p class="text-xs text-tx-soft">Sin cambios</p>
                <p class="mt-1 text-2xl font-semibold text-slate-700">{{ number_format($counts['sin_cambios'] ?? 0) }}</p>
                <p class="mt-1 text-[11px] text-slate-500">Idénticas a versión vigente</p>
            </div>
            <div class="rounded-lg border border-red-200 bg-red-50/50 p-4 shadow-card">
                <p class="text-xs text-red-800">Conflictos / Rechazos</p>
                <p class="mt-1 text-2xl font-semibold text-red-900">{{ number_format($blockingErrors) }}</p>
                <p class="mt-1 text-[11px] text-red-700">{{ $blockingErrors > 0 ? 'Bloquean publicación' : 'Ninguno detectado' }}</p>
            </div>
        </div>

        <!-- Confirmación de la versión -->
        @if ($batch->estado === 'en_revision')
            <x-card class="mb-5" title="Publicación de nueva versión" description="Al confirmar, se creará una versión histórica completa y se activará de forma atómica.">
                @if ($blockingErrors > 0)
                    <div class="rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-950">
                        <p class="font-medium">El archivo contiene {{ number_format($blockingErrors) }} fila(s) con inconsistencias críticas (claves vacías, duplicadas dentro del mismo archivo o campos obligatorios faltantes).</p>
                        <p class="mt-1 text-xs">Por política de integridad estricta, no se permite publicar una versión del catálogo incompleta o contradictoria. Corrige el archivo de LOG y vuelve a analizarlo.</p>
                    </div>
                @else
                    <form method="POST" action="{{ route('gestion.catalogo.importaciones.confirmar', $batch) }}" class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        @csrf
                        <div class="text-sm">
                            <p class="font-medium text-slate-900">Validación estructural e integridad satisfactorias.</p>
                            <p class="mt-0.5 text-xs text-tx-soft">
                                Se publicará una versión oficial con {{ number_format($batch->total_aceptadas) }} registros. Las versiones previas se conservarán intactas para auditoría histórica.
                            </p>
                        </div>
                        <button type="submit" class="shrink-0 rounded-md bg-inst-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-inst-900 focus:outline-none focus:ring-2 focus:ring-inst-700 focus:ring-offset-2">
                            Confirmar y activar versión
                        </button>
                    </form>
                @endif
            </x-card>
        @elseif ($batch->estado === 'completado')
            <div class="mb-5 rounded-lg border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-950">
                <p class="font-medium">Este lote ya fue confirmado y publicado.</p>
                <p class="mt-1 text-xs text-emerald-800">
                    Fecha de confirmación: {{ $batch->fecha_confirmacion?->format('d/m/Y H:i') ?? '—' }}. Los registros forman parte del historial institucional.
                </p>
            </div>
        @endif

        <!-- Filtros y Tabla de Registros en Staging -->
        <x-card title="Filas analizadas en staging" description="Detalle de registros y clasificación para la publicación.">
            <form method="GET" action="{{ route('gestion.catalogo.importaciones.preview', $batch) }}" class="mb-4 grid grid-cols-1 gap-3 rounded-lg bg-slate-50 p-4 sm:grid-cols-3">
                <label class="text-xs font-medium text-slate-600">Buscar por clave, municipio o agrupamiento
                    <input type="text" name="buscar" value="{{ $search }}" placeholder="Ej. A-001, Toluca..." class="mt-1 block w-full rounded-md border-slate-300 text-sm">
                </label>
                <label class="text-xs font-medium text-slate-600">Filtrar por acción
                    <select name="accion" class="mt-1 block w-full rounded-md border-slate-300 text-sm">
                        <option value="">Todas las acciones</option>
                        <option value="nuevo" @selected($action_filter === 'nuevo')>Nuevos</option>
                        <option value="actualizar" @selected($action_filter === 'actualizar')>Actualizaciones</option>
                        <option value="sin_cambios" @selected($action_filter === 'sin_cambios')>Sin cambios</option>
                        <option value="conflicto" @selected($action_filter === 'conflicto')>Conflictos</option>
                        <option value="rechazado" @selected($action_filter === 'rechazado')>Rechazados</option>
                    </select>
                </label>
                <div class="flex items-end gap-2">
                    <button type="submit" class="rounded-md bg-inst-900 px-4 py-2 text-sm font-medium text-white hover:bg-inst-700">
                        Filtrar
                    </button>
                    <a href="{{ route('gestion.catalogo.importaciones.preview', $batch) }}" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-100">
                        Limpiar
                    </a>
                </div>
            </form>

            <!-- Badges interactivos tipo Chips para filtrado rápido -->
            <div class="mb-4 flex flex-wrap items-center gap-2 border-b border-slate-200 pb-3">
                <span class="mr-1 text-xs font-semibold uppercase tracking-wider text-tx-soft">Filtro rápido:</span>
                <a href="{{ route('gestion.catalogo.importaciones.preview', array_merge(request()->except(['accion', 'page']), ['batch' => $batch])) }}"
                   class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ empty($action_filter) ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">
                    <span>Todos</span>
                    <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ empty($action_filter) ? 'bg-slate-700 text-white' : 'bg-slate-200 text-slate-600' }}">
                        {{ number_format($batch->total_leidas) }}
                    </span>
                </a>
                <a href="{{ route('gestion.catalogo.importaciones.preview', array_merge(request()->except(['accion', 'page']), ['batch' => $batch, 'accion' => 'nuevo'])) }}"
                   class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ $action_filter === 'nuevo' ? 'bg-emerald-700 text-white' : 'bg-emerald-50 text-emerald-800 hover:bg-emerald-100' }}">
                    <span>Nuevas claves</span>
                    <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $action_filter === 'nuevo' ? 'bg-emerald-800 text-white' : 'bg-emerald-200/70 text-emerald-800' }}">
                        {{ number_format($counts['nuevo'] ?? 0) }}
                    </span>
                </a>
                <a href="{{ route('gestion.catalogo.importaciones.preview', array_merge(request()->except(['accion', 'page']), ['batch' => $batch, 'accion' => 'actualizar'])) }}"
                   class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ $action_filter === 'actualizar' ? 'bg-blue-700 text-white' : 'bg-blue-50 text-blue-800 hover:bg-blue-100' }}">
                    <span>Actualizaciones</span>
                    <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $action_filter === 'actualizar' ? 'bg-blue-800 text-white' : 'bg-blue-200/70 text-blue-800' }}">
                        {{ number_format($counts['actualizar'] ?? 0) }}
                    </span>
                </a>
                <a href="{{ route('gestion.catalogo.importaciones.preview', array_merge(request()->except(['accion', 'page']), ['batch' => $batch, 'accion' => 'sin_cambios'])) }}"
                   class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ $action_filter === 'sin_cambios' ? 'bg-slate-700 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">
                    <span>Sin cambios</span>
                    <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $action_filter === 'sin_cambios' ? 'bg-slate-800 text-white' : 'bg-slate-200 text-slate-600' }}">
                        {{ number_format($counts['sin_cambios'] ?? 0) }}
                    </span>
                </a>
                <a href="{{ route('gestion.catalogo.importaciones.preview', array_merge(request()->except(['accion', 'page']), ['batch' => $batch, 'accion' => 'conflicto'])) }}"
                   class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ in_array($action_filter, ['conflicto', 'rechazado'], true) ? 'bg-red-700 text-white' : 'bg-red-50 text-red-800 hover:bg-red-100' }}">
                    <span>Conflictos / Rechazos</span>
                    <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ in_array($action_filter, ['conflicto', 'rechazado'], true) ? 'bg-red-800 text-white' : 'bg-red-200/70 text-red-800' }}">
                        {{ number_format($blockingErrors) }}
                    </span>
                </a>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-tx-soft">
                        <tr>
                            <th class="px-3 py-2.5"># Fila</th>
                            <th class="px-3 py-2.5">CLAVE</th>
                            <th class="px-3 py-2.5">Agrupamiento</th>
                            <th class="px-3 py-2.5">Municipio</th>
                            <th class="px-3 py-2.5">Región</th>
                            <th class="px-3 py-2.5">Zona Militar</th>
                            <th class="px-3 py-2.5">Batallón</th>
                            <th class="px-3 py-2.5">Acción</th>
                            <th class="px-3 py-2.5">Detalle / Error</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($rows as $row)
                            @php
                                $d = is_array($row->datos) ? $row->datos : json_decode((string) $row->datos, true);
                                $errs = is_array($row->errores) ? $row->errores : json_decode((string) $row->errores, true);
                                $badgeTone = match ($row->accion) {
                                    'nuevo' => 'success',
                                    'actualizar' => 'info',
                                    'sin_cambios' => 'neutral',
                                    'conflicto' => 'danger',
                                    'rechazado' => 'danger',
                                    default => 'neutral',
                                };
                            @endphp
                            <tr class="hover:bg-slate-50/80">
                                <td class="px-3 py-2 text-xs text-tx-soft">{{ $row->numero_fila }}</td>
                                <td class="px-3 py-2 font-mono text-xs font-semibold text-inst-900">{{ $row->clave_origen ?? '—' }}</td>
                                <td class="px-3 py-2 text-xs text-slate-800">{{ $d['agrupamiento'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-xs text-slate-700">{{ $d['municipio'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-xs text-tx-soft">{{ $d['region'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-xs text-tx-soft">{{ $d['zona_militar'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-xs text-tx-soft">{{ $d['batallon'] ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    <x-status-badge :label="str($row->accion)->replace('_', ' ')->title()" :tone="$badgeTone"/>
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    @if ($errs && count($errs) > 0)
                                        <span class="text-red-700 font-medium">{{ implode(', ', $errs) }}</span>
                                    @else
                                        <span class="text-slate-400">Válido</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-8 text-center text-sm text-tx-soft">
                                    No se encontraron filas que coincidan con los criterios de búsqueda.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $rows->links() }}
            </div>
        </x-card>
    @endif
</div>
@endsection

