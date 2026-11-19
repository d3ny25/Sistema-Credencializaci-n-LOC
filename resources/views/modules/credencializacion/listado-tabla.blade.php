{{-- Filtros de búsqueda, acción de nueva solicitud y tabla paginada de expedientes --}}
<x-card title="Expedientes de solicitud" icon="idcard" description="Búsqueda y gestión de solicitudes de credencialización institucional.">
    <x-slot:actions>
        <button type="button" id="btn-nueva-solicitud" @click="modalNuevaSolicitud = true" class="btn btn-primary">
            <x-icon name="plus" class="h-4 w-4"/>
            Nueva solicitud
        </button>
    </x-slot:actions>
    <form method="GET" action="{{ route('credencializacion') }}" class="panel-muted mb-5 p-4" role="search" aria-label="Filtrar solicitudes">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <label class="field-label">
                Oficio, tarjeta o área
                <input type="search" name="buscar" value="{{ $filters['buscar'] ?? '' }}" placeholder="Ej. OFICIO-001 o TARJETA-001">
            </label>
            <label class="field-label">
                Estado
                <select name="estado">
                    <option value="Todos" @selected(($filters['estado'] ?? '') === 'Todos' || empty($filters['estado']))>Todos los estados</option>
                    <option value="recibida" @selected(($filters['estado'] ?? '') === 'recibida')>Recibida</option>
                    <option value="evaluada" @selected(($filters['estado'] ?? '') === 'evaluada')>Evaluada</option>
                    <option value="enviada_impresion" @selected(($filters['estado'] ?? '') === 'enviada_impresion')>Enviada a impresión</option>
                </select>
            </label>
            <label class="field-label">
                Desde
                <input type="date" name="desde" value="{{ $filters['desde'] ?? '' }}">
            </label>
            <label class="field-label">
                Hasta
                <input type="date" name="hasta" value="{{ $filters['hasta'] ?? '' }}">
            </label>
            <div class="flex items-end gap-2">
                <button type="submit" class="btn btn-primary flex-1">
                    Filtrar
                </button>
                <a href="{{ route('credencializacion') }}" class="btn btn-secondary">
                    Limpiar
                </a>
            </div>
        </div>
    </form>

    @if ($solicitudes && $solicitudes->isNotEmpty())
        <div class="table-wrap">
            <table class="table-data">
                <thead>
                    <tr>
                        <th scope="col">Tarjeta / oficio</th>
                        <th scope="col">Recepción</th>
                        <th scope="col">Área solicitante</th>
                        <th scope="col" class="text-right">Elementos</th>
                        <th scope="col" class="text-right">Aptos</th>
                        <th scope="col" class="text-right">Descartados</th>
                        <th scope="col">Estado</th>
                        <th scope="col"><span class="sr-only">Acciones</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($solicitudes as $s)
                        @php
                            [$estadoLabel, $estadoTono] = match ($s->estado_general) {
                                'enviada_impresion' => ['Enviada a impresión', 'success'],
                                'evaluada' => ['Evaluada', 'info'],
                                default => [ucfirst((string) $s->estado_general), 'warning'],
                            };
                        @endphp
                        <tr>
                            <td class="whitespace-nowrap">
                                <a href="{{ route('credencializacion.detalle', $s->id) }}" class="block font-mono text-xs font-semibold text-inst-800 hover:text-inst-900 hover:underline">{{ $s->numero_tarjeta }}</a>
                                <span class="block font-mono text-2xs text-tx-soft">{{ $s->numero_oficio }}</span>
                            </td>
                            <td class="whitespace-nowrap text-xs text-tx-soft">{{ $s->fecha_recepcion ? $s->fecha_recepcion->format('d/m/Y') : '—' }}</td>
                            <td class="max-w-[16rem]"><span class="block truncate text-xs" title="{{ $s->area_solicitante }}">{{ $s->area_solicitante ?: '—' }}</span></td>
                            <td class="text-right text-xs font-semibold tabular-nums text-inst-900">{{ number_format((int) $s->total_elementos) }}</td>
                            <td class="text-right text-xs font-semibold tabular-nums text-emerald-700">{{ number_format((int) $s->total_aptos) }}</td>
                            <td class="text-right text-xs font-semibold tabular-nums text-red-700">{{ number_format((int) $s->total_descartados) }}</td>
                            <td class="whitespace-nowrap"><x-status-badge :label="$estadoLabel" :tone="$estadoTono" size="xs"/></td>
                            <td class="whitespace-nowrap text-right">
                                <a href="{{ route('credencializacion.detalle', $s->id) }}" class="btn btn-secondary btn-sm" aria-label="Abrir expediente {{ $s->numero_tarjeta }}">
                                    Abrir expediente <x-icon name="chevronRight" class="h-3.5 w-3.5"/>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($solicitudes->hasPages())
            <div class="mt-4">
                {{ $solicitudes->links() }}
            </div>
        @endif
    @else
        <x-empty-table :columns="['Tarjeta', 'Oficio', 'Fecha de recepción', 'Área solicitante', 'Elementos', 'Aptos', 'Descartados', 'Estado', 'Acciones']" message="Sin solicitudes registradas. Utiliza el botón Nueva Solicitud para registrar un expediente."/>
    @endif
</x-card>

