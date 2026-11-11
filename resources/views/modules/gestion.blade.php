@extends('layouts.app')

@section('title', 'Gestión de información')
@section('heading', 'Gestión de información')
@section('subheading', 'Fuentes de referencia institucional e historial de importaciones')

@section('content')
    <x-module-tabs :active="$section" :items="[
        ['key' => 'fuentes', 'label' => 'Fuentes de información', 'route' => 'gestion'],
        ['key' => 'historial', 'label' => 'Historial de importaciones', 'route' => 'gestion.historial'],
    ]"/>

    @if (session('success'))
        <div role="status" class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif

    @if ($section === 'historial')
        <div class="mb-5 grid grid-cols-1 gap-4 md:grid-cols-3">
            @foreach ([
                ['title' => 'Importaciones registradas', 'value' => number_format($summary['total'])],
                ['title' => 'Último procesamiento', 'value' => $summary['last_processed_at'] ? \Illuminate\Support\Carbon::parse($summary['last_processed_at'])->format('d/m/Y H:i') : 'Sin importaciones'],
                ['title' => 'Filas rechazadas', 'value' => number_format($summary['rejected'])],
            ] as $metric)
                <x-card :title="$metric['title']"><p class="text-2xl font-semibold text-inst-900">{{ $metric['value'] }}</p><p class="mt-1 text-xs text-tx-soft">Datos de los lotes persistidos.</p></x-card>
            @endforeach
        </div>
        <x-card title="Historial de importaciones" description="Lotes registrados y resultados de validación.">
            <form method="GET" action="{{ route('gestion.historial') }}" class="mb-5 grid grid-cols-1 gap-3 rounded-lg bg-slate-50 p-4 sm:grid-cols-2 lg:grid-cols-5">
                <label class="text-xs font-medium text-slate-600">Estado
                    <select name="estado" class="mt-1 block w-full rounded-md border-slate-300 text-sm">
                        <option value="">Todos</option>
                        @foreach (['pendiente', 'procesando', 'en_revision', 'completado', 'fallido', 'fallido_parcial'] as $status)
                            <option value="{{ $status }}" @selected(($filters['estado'] ?? '') === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-xs font-medium text-slate-600">Fuente
                    <select name="tipo_fuente" class="mt-1 block w-full rounded-md border-slate-300 text-sm">
                        <option value="">Todas</option>
                        @foreach ($sources as $source)
                            <option value="{{ $source }}" @selected(($filters['tipo_fuente'] ?? '') === $source)>{{ str($source)->replace('_', ' ')->title() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-xs font-medium text-slate-600">Desde
                    <input type="date" name="desde" value="{{ $filters['desde'] ?? '' }}" class="mt-1 block w-full rounded-md border-slate-300 text-sm">
                </label>
                <label class="text-xs font-medium text-slate-600">Hasta
                    <input type="date" name="hasta" value="{{ $filters['hasta'] ?? '' }}" class="mt-1 block w-full rounded-md border-slate-300 text-sm">
                </label>
                <div class="flex items-end gap-2">
                    <button class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white">Filtrar</button>
                    <a href="{{ route('gestion.historial') }}" class="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-700">Limpiar</a>
                </div>
            </form>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-tx-soft">
                        <tr>
                            <th class="px-4 py-3">Fecha y hora</th>
                            <th class="px-4 py-3">Fuente</th>
                            <th class="px-4 py-3">Archivo</th>
                            <th class="px-4 py-3">Leídas</th>
                            <th class="px-4 py-3">Aceptadas</th>
                            <th class="px-4 py-3">Rechazadas</th>
                            <th class="px-4 py-3">Actualizadas</th>
                            <th class="px-4 py-3">Insertadas</th>
                            <th class="px-4 py-3">Sin cambios</th>
                            <th class="px-4 py-3">Responsable</th>
                            <th class="px-4 py-3">Estado</th>
                            <th class="px-4 py-3">Detalle</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($batches as $batch)
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3">{{ $batch->fecha_procesamiento?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="px-4 py-3">{{ str($batch->tipo_fuente)->replace('_', ' ')->title() }}</td>
                                <td class="max-w-64 truncate px-4 py-3" title="{{ $batch->nombre_archivo }}">{{ $batch->nombre_archivo }}</td>
                                <td class="px-4 py-3">{{ number_format($batch->total_leidas) }}</td>
                                <td class="px-4 py-3">{{ number_format($batch->total_aceptadas) }}</td>
                                <td class="px-4 py-3">{{ number_format($batch->total_rechazadas) }}</td>
                                <td class="px-4 py-3">{{ number_format($batch->total_actualizadas) }}</td>
                                <td class="px-4 py-3">{{ number_format($batch->total_insertadas) }}</td>
                                <td class="px-4 py-3">{{ number_format($batch->total_sin_cambios) }}</td>
                                <td class="px-4 py-3">{{ $batch->usuario?->name ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    @php
                                        $histTone = match($batch->estado) {
                                            'completado' => 'success',
                                            'en_revision', 'fallido_parcial' => 'warning',
                                            'fallido' => 'danger',
                                            'pendiente', 'procesando' => 'info',
                                            default => 'neutral'
                                        };
                                    @endphp
                                    <x-status-badge :label="str($batch->estado)->replace('_', ' ')->title()" :tone="$histTone" size="xs"/>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    @if ($batch->tipo_fuente === 'personal_autorizado_loc' && in_array($batch->estado, ['procesando', 'en_revision', 'completado', 'fallido', 'fallido_parcial'], true))
                                        <a href="{{ route('gestion.importaciones.preview', $batch) }}" class="font-medium text-inst-700 hover:text-inst-900">Ver filas</a>
                                    @elseif ($batch->tipo_fuente === 'armamento_loc' && in_array($batch->estado, ['procesando', 'en_revision', 'completado', 'fallido', 'fallido_parcial'], true))
                                        <a href="{{ route('gestion.armamento.importaciones.preview', $batch) }}" class="font-medium text-inst-700 hover:text-inst-900">Ver filas</a>
                                    @elseif ($batch->tipo_fuente === 'vigencias_credenciales' && in_array($batch->estado, ['procesando', 'en_revision', 'completado', 'fallido', 'fallido_parcial'], true))
                                        <a href="{{ route('gestion.vigencias.importaciones.preview', $batch) }}" class="font-medium text-inst-700 hover:text-inst-900">Ver filas</a>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="12" class="px-4 py-10 text-center text-sm text-tx-soft">Aún no hay importaciones registradas para estos filtros.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $batches->links() }}</div>
        </x-card>
    @else
        <div class="mb-5 rounded-lg border border-inst-100 bg-inst-50/70 p-4 text-sm text-inst-900">
            El archivo se analiza primero en una vista previa. Sólo las filas válidas se aplican al padrón después de confirmar la importación.
        </div>
        @unless ($import_schema_ready)
            <div class="mb-5 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950" role="alert">
                El importador no está habilitado en esta base: faltan las migraciones LOC 2026_10_21_000002 y 2026_10_21_000003. No cargues archivos hasta que el esquema haya sido revisado y actualizado con autorización.
            </div>
        @endunless
        <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['title' => 'Personas registradas', 'value' => number_format($summary['people']), 'description' => 'Registros persistidos en el padrón LOC'],
                ['title' => 'Armas registradas', 'value' => number_format($summary['weapons']), 'description' => 'Registros persistidos en el inventario LOC'],
                ['title' => 'Credenciales registradas', 'value' => number_format($summary['credentials'] ?? 0), 'description' => 'Registros persistidos en base de vigencias'],
                ['title' => 'Claves organizacionales', 'value' => number_format($summary['catalog_keys'] ?? 0), 'description' => 'Claves activas en catálogo vigente'],
            ] as $metric)
                <x-card :title="$metric['title']"><p class="text-2xl font-semibold text-inst-900">{{ $metric['value'] }}</p><p class="mt-1 text-xs text-tx-soft">{{ $metric['description'] }}</p></x-card>
            @endforeach
        </div>
        <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
            @foreach ([
                ['LOC · Personal', 'loc_personas', 'CUIP, nombre, RFC, CURP, categoría, estatus, región y adscripción.'],
                ['LOC · Armamento', 'loc_armas', 'Matrícula, tipo, clase, calibre, marca, modelo, RFA y situación.'],
                ['Base de credenciales', 'loc_vigencias_credenciales', 'Oficio, número, vigencia, situación especial y relación con persona o arma.'],
                ['Catálogo organizacional', 'loc_catalogo_organizacional', 'Claves, jerarquía, municipio, región y adscripción.'],
            ] as [$title, $table, $fields])
                @if ($table === 'loc_personas')
                    @include('modules.gestion-upload-card')
                @elseif ($table === 'loc_armas')
                    @include('modules.gestion-armas-upload-card')
                @elseif ($table === 'loc_vigencias_credenciales')
                    @include('modules.gestion-credenciales-upload-card')
                @elseif ($table === 'loc_catalogo_organizacional')
                    @include('modules.gestion-catalogo-upload-card')
                @else
                    <x-card :title="$title" :description="'Fuente persistente prevista: '.$table">
                        <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                            <div><dt class="text-xs text-tx-soft">Campos principales</dt><dd class="mt-1 leading-5 text-tx">{{ $fields }}</dd></div>
                            <div><dt class="text-xs text-tx-soft">Última versión</dt><dd class="mt-1 text-tx">No consultada</dd><dt class="mt-3 text-xs text-tx-soft">Estado de carga</dt><dd class="mt-1"><x-status-badge label="Sin verificar" tone="neutral"/></dd></div>
                        </dl>
                        <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                            <button type="button" disabled title="La operación para esta fuente aún no está disponible." class="rounded-md bg-slate-100 px-3 py-2 text-xs font-medium text-slate-400">Seleccionar archivo · no disponible</button>
                            <button type="button" disabled title="No se ejecutan actualizaciones sin una operación validada." class="rounded-md border border-slate-200 px-3 py-2 text-xs font-medium text-slate-400">Actualizar fuente · no disponible</button>
                            <button type="button" disabled title="Sin datos autorizados para mostrar." class="rounded-md border border-slate-200 px-3 py-2 text-xs font-medium text-slate-400">Ver detalle · no disponible</button>
                        </div>
                    </x-card>
                @endif
            @endforeach
        </div>
        <div class="mt-5">
            <x-card title="Últimas importaciones" description="Estado persistido de los lotes recientes.">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-tx-soft">
                            <tr><th class="px-4 py-3">Fecha</th><th class="px-4 py-3">Archivo</th><th class="px-4 py-3">Leídas</th><th class="px-4 py-3">Estado</th><th class="px-4 py-3">Detalle</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($batches as $batch)
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-3">{{ $batch->fecha_procesamiento?->format('d/m/Y H:i') ?? '—' }}</td>
                                    <td class="px-4 py-3">{{ $batch->nombre_archivo }}</td>
                                    <td class="px-4 py-3">{{ number_format($batch->total_leidas) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        @php
                                            $recentTone = match($batch->estado) {
                                                'completado' => 'success',
                                                'en_revision', 'fallido_parcial' => 'warning',
                                                'fallido' => 'danger',
                                                'pendiente', 'procesando' => 'info',
                                                default => 'neutral'
                                            };
                                        @endphp
                                        <x-status-badge :label="str($batch->estado)->replace('_', ' ')->title()" :tone="$recentTone" size="xs"/>
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($batch->tipo_fuente === 'personal_autorizado_loc' && in_array($batch->estado, ['procesando', 'en_revision', 'completado', 'fallido', 'fallido_parcial'], true))
                                            <a href="{{ route('gestion.importaciones.preview', $batch) }}" class="font-medium text-inst-700 hover:text-inst-900">Ver detalle</a>
                                        @elseif ($batch->tipo_fuente === 'armamento_loc' && in_array($batch->estado, ['procesando', 'en_revision', 'completado', 'fallido', 'fallido_parcial'], true))
                                            <a href="{{ route('gestion.armamento.importaciones.preview', $batch) }}" class="font-medium text-inst-700 hover:text-inst-900">Ver detalle</a>
                                        @elseif ($batch->tipo_fuente === 'vigencias_credenciales' && in_array($batch->estado, ['procesando', 'en_revision', 'completado', 'fallido', 'fallido_parcial'], true))
                                            <a href="{{ route('gestion.vigencias.importaciones.preview', $batch) }}" class="font-medium text-inst-700 hover:text-inst-900">Ver detalle</a>
                                        @elseif ($batch->tipo_fuente === 'catalogo_organizacional' && in_array($batch->estado, ['procesando', 'en_revision', 'completado', 'fallido', 'fallido_parcial'], true))
                                            <a href="{{ route('gestion.catalogo.importaciones.preview', $batch) }}" class="font-medium text-inst-700 hover:text-inst-900">Ver detalle</a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-8 text-center text-sm text-tx-soft">Aún no hay importaciones registradas.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <a href="{{ route('gestion.historial') }}" class="mt-4 inline-flex text-sm font-medium text-inst-700 hover:text-inst-900">Ver historial completo</a>
            </x-card>
        </div>
    @endif
@endsection
