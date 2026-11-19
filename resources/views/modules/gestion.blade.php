@extends('layouts.app')

@section('title', 'Gestión de información')
@section('heading', 'Gestión de información')
@section('subheading', 'Fuentes de referencia institucional e historial de importaciones')

@php
    // Una sola regla para enlazar el detalle de un lote (antes duplicada y sin catálogo en el historial).
    $rutasDetalle = [
        'personal_autorizado_loc' => 'gestion.importaciones.preview',
        'armamento_loc' => 'gestion.armamento.importaciones.preview',
        'vigencias_credenciales' => 'gestion.vigencias.importaciones.preview',
        'catalogo_organizacional' => 'gestion.catalogo.importaciones.preview',
    ];
    $estadosConDetalle = ['procesando', 'en_revision', 'completado', 'fallido', 'fallido_parcial'];
    $urlDetalle = fn ($batch): ?string => isset($rutasDetalle[$batch->tipo_fuente]) && in_array($batch->estado, $estadosConDetalle, true)
        ? route($rutasDetalle[$batch->tipo_fuente], $batch)
        : null;
    $tonoLote = fn (?string $estado): string => match ($estado) {
        'completado' => 'success',
        'en_revision', 'fallido_parcial' => 'warning',
        'fallido' => 'danger',
        'pendiente', 'procesando' => 'info',
        default => 'neutral',
    };
    $etiqueta = fn (?string $valor): string => str((string) $valor)->replace('_', ' ')->title()->toString();
@endphp

@section('content')
    <x-module-tabs :active="$section" :items="[
        ['key' => 'fuentes', 'label' => 'Fuentes de información', 'route' => 'gestion'],
        ['key' => 'historial', 'label' => 'Historial de importaciones', 'route' => 'gestion.historial'],
    ]"/>

    @if (session('success'))
        <div role="status" class="alert alert-success mb-5">
            <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0"/>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if ($section === 'historial')
        <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-kpi label="Importaciones registradas" :value="number_format($summary['total'])" hint="Datos de los lotes persistidos." icon="layers"/>
            <x-kpi label="Último procesamiento" :value="$summary['last_processed_at'] ? \Illuminate\Support\Carbon::parse($summary['last_processed_at'])->format('d/m/Y H:i') : 'Sin importaciones'" hint="Datos de los lotes persistidos." icon="clock"/>
            <x-kpi label="Filas rechazadas" :value="number_format($summary['rejected'])" hint="Datos de los lotes persistidos." icon="alert" :tone="$summary['rejected'] > 0 ? 'danger' : 'neutral'"/>
        </div>

        <x-card title="Historial de importaciones" description="Lotes registrados y resultados de validación.">
            <form method="GET" action="{{ route('gestion.historial') }}" class="panel-muted mb-5 grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-5" role="search" aria-label="Filtrar historial">
                <label class="field-label">Estado
                    <select name="estado">
                        <option value="">Todos</option>
                        @foreach (['pendiente', 'procesando', 'en_revision', 'completado', 'fallido', 'fallido_parcial'] as $status)
                            <option value="{{ $status }}" @selected(($filters['estado'] ?? '') === $status)>{{ $etiqueta($status) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field-label">Fuente
                    <select name="tipo_fuente">
                        <option value="">Todas</option>
                        @foreach ($sources as $source)
                            <option value="{{ $source }}" @selected(($filters['tipo_fuente'] ?? '') === $source)>{{ $etiqueta($source) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field-label">Desde
                    <input type="date" name="desde" value="{{ $filters['desde'] ?? '' }}">
                </label>
                <label class="field-label">Hasta
                    <input type="date" name="hasta" value="{{ $filters['hasta'] ?? '' }}">
                </label>
                <div class="flex items-end gap-2">
                    <button type="submit" class="btn btn-primary flex-1"><x-icon name="search" class="h-4 w-4"/>Filtrar</button>
                    <a href="{{ route('gestion.historial') }}" class="btn btn-secondary">Limpiar</a>
                </div>
            </form>

            <div class="table-wrap">
                <table class="table-data table-compact">
                    <thead>
                        <tr>
                            <th scope="col">Fecha y hora</th>
                            <th scope="col">Fuente</th>
                            <th scope="col">Archivo</th>
                            <th scope="col" class="text-right">Leídas</th>
                            <th scope="col" class="text-right">Aceptadas</th>
                            <th scope="col" class="text-right">Rechazadas</th>
                            <th scope="col" class="text-right">Actualizadas</th>
                            <th scope="col" class="text-right">Insertadas</th>
                            <th scope="col" class="text-right">Sin cambios</th>
                            <th scope="col">Responsable</th>
                            <th scope="col">Estado</th>
                            <th scope="col"><span class="sr-only">Detalle</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($batches as $batch)
                            <tr>
                                <td class="whitespace-nowrap">{{ $batch->fecha_procesamiento?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="whitespace-nowrap">{{ $etiqueta($batch->tipo_fuente) }}</td>
                                <td class="max-w-64 truncate" title="{{ $batch->nombre_archivo }}">{{ $batch->nombre_archivo }}</td>
                                <td class="text-right tabular-nums">{{ number_format($batch->total_leidas) }}</td>
                                <td class="text-right tabular-nums">{{ number_format($batch->total_aceptadas) }}</td>
                                <td class="text-right tabular-nums {{ $batch->total_rechazadas > 0 ? 'font-semibold text-red-700' : '' }}">{{ number_format($batch->total_rechazadas) }}</td>
                                <td class="text-right tabular-nums">{{ number_format($batch->total_actualizadas) }}</td>
                                <td class="text-right tabular-nums">{{ number_format($batch->total_insertadas) }}</td>
                                <td class="text-right tabular-nums">{{ number_format($batch->total_sin_cambios) }}</td>
                                <td class="whitespace-nowrap">{{ $batch->usuario?->name ?? '—' }}</td>
                                <td class="whitespace-nowrap"><x-status-badge :label="$etiqueta($batch->estado)" :tone="$tonoLote($batch->estado)" size="xs"/></td>
                                <td class="whitespace-nowrap text-right">
                                    @if ($url = $urlDetalle($batch))
                                        <a href="{{ $url }}" class="btn btn-ghost btn-sm">Ver filas</a>
                                    @else
                                        <span class="text-tx-soft">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="12" class="py-10 text-center text-sm text-tx-soft">Aún no hay importaciones registradas para estos filtros.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $batches->links() }}</div>
        </x-card>
    @else
        <div class="alert alert-info mb-5">
            <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0"/>
            <span>El archivo se analiza primero en una vista previa. Solo las filas válidas se aplican al padrón después de confirmar la importación.</span>
        </div>

        @unless ($import_schema_ready)
            <div class="alert alert-warning mb-5" role="alert">
                <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0"/>
                <span>El importador no está habilitado en esta base: faltan las migraciones LOC 2026_10_21_000002 y 2026_10_21_000003. No cargues archivos hasta que el esquema haya sido revisado y actualizado con autorización.</span>
            </div>
        @endunless

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-kpi label="Personas registradas" :value="number_format($summary['people'])" hint="Registros persistidos en el padrón LOC" icon="users" tone="info"/>
            <x-kpi label="Armas registradas" :value="number_format($summary['weapons'])" hint="Registros persistidos en el inventario LOC" icon="shield" tone="neutral"/>
            <x-kpi label="Credenciales registradas" :value="number_format($summary['credentials'] ?? 0)" hint="Registros persistidos en base de vigencias" icon="creditcard" tone="success"/>
            <x-kpi label="Claves organizacionales" :value="number_format($summary['catalog_keys'] ?? 0)" hint="Claves activas en catálogo vigente" icon="layers" tone="neutral"/>
        </div>

        <h2 class="eyebrow mb-3">Fuentes de referencia</h2>
        <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
            @include('modules.gestion-upload-card')
            @include('modules.gestion-armas-upload-card')
            @include('modules.gestion-credenciales-upload-card')
            @include('modules.gestion-catalogo-upload-card')
        </div>

        <div class="mt-6">
            <x-card title="Últimas importaciones" icon="clock" description="Estado persistido de los lotes recientes.">
                <x-slot:actions>
                    <a href="{{ route('gestion.historial') }}" class="btn btn-ghost btn-sm">Ver historial completo <x-icon name="arrowRight" class="h-3.5 w-3.5"/></a>
                </x-slot:actions>
                <div class="table-wrap">
                    <table class="table-data">
                        <thead>
                            <tr>
                                <th scope="col">Fecha</th>
                                <th scope="col">Fuente</th>
                                <th scope="col">Archivo</th>
                                <th scope="col" class="text-right">Leídas</th>
                                <th scope="col">Estado</th>
                                <th scope="col"><span class="sr-only">Detalle</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($batches as $batch)
                                <tr>
                                    <td class="whitespace-nowrap">{{ $batch->fecha_procesamiento?->format('d/m/Y H:i') ?? '—' }}</td>
                                    <td class="whitespace-nowrap">{{ $etiqueta($batch->tipo_fuente) }}</td>
                                    <td class="max-w-72 truncate" title="{{ $batch->nombre_archivo }}">{{ $batch->nombre_archivo }}</td>
                                    <td class="text-right tabular-nums">{{ number_format($batch->total_leidas) }}</td>
                                    <td class="whitespace-nowrap"><x-status-badge :label="$etiqueta($batch->estado)" :tone="$tonoLote($batch->estado)" size="xs"/></td>
                                    <td class="whitespace-nowrap text-right">
                                        @if ($url = $urlDetalle($batch))
                                            <a href="{{ $url }}" class="btn btn-ghost btn-sm">Ver detalle</a>
                                        @else
                                            <span class="text-tx-soft">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="py-8 text-center text-sm text-tx-soft">Aún no hay importaciones registradas.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-card>
        </div>
    @endif
@endsection
