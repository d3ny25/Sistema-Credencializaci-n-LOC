{{-- Filtros de búsqueda, acción de nueva solicitud y tabla paginada de expedientes --}}
<x-card title="Expedientes de Solicitud" description="Búsqueda y gestión de solicitudes de credencialización institucional.">
    <form method="GET" action="{{ route('credencializacion') }}" class="mb-5">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <label class="text-xs font-medium text-tx-soft">
                Oficio, tarjeta o área
                <input type="search" name="buscar" value="{{ $filters['buscar'] ?? '' }}" placeholder="Ej. OFICIO-001 o TARJETA-001" class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200 focus:ring-2 focus:ring-inst-700">
            </label>
            <label class="text-xs font-medium text-tx-soft">
                Estado
                <select name="estado" class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200 focus:ring-2 focus:ring-inst-700">
                    <option value="Todos" @selected(($filters['estado'] ?? '') === 'Todos' || empty($filters['estado']))>Todos los estados</option>
                    <option value="recibida" @selected(($filters['estado'] ?? '') === 'recibida')>Recibida</option>
                    <option value="evaluada" @selected(($filters['estado'] ?? '') === 'evaluada')>Evaluada</option>
                    <option value="enviada_impresion" @selected(($filters['estado'] ?? '') === 'enviada_impresion')>Enviada a impresión</option>
                </select>
            </label>
            <label class="text-xs font-medium text-tx-soft">
                Desde
                <input type="date" name="desde" value="{{ $filters['desde'] ?? '' }}" class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200">
            </label>
            <label class="text-xs font-medium text-tx-soft">
                Hasta
                <input type="date" name="hasta" value="{{ $filters['hasta'] ?? '' }}" class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200">
            </label>
            <div class="flex items-end gap-2">
                <button type="submit" class="w-full rounded-md bg-inst-700 px-3 py-2 text-sm font-semibold text-white hover:bg-inst-800 transition">
                    Filtrar
                </button>
                <a href="{{ route('credencializacion') }}" class="rounded-md border border-slate-200 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50 transition">
                    Limpiar
                </a>
            </div>
        </div>
    </form>

    <div class="mb-4 flex justify-end">
        <button type="button" id="btn-nueva-solicitud" @click="modalNuevaSolicitud = true" class="inline-flex items-center gap-2 rounded-md bg-inst-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-inst-800 transition">
            <x-icon name="plus" class="h-4 w-4"/>
            Nueva solicitud
        </button>
    </div>

    @if ($solicitudes && $solicitudes->isNotEmpty())
        <div class="overflow-x-auto rounded-lg bg-white shadow-card ring-1 ring-slate-900/5">
            <table class="min-w-full divide-y divide-slate-100">
                <thead class="bg-slate-50/80">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-tx-soft">Tarjeta</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-tx-soft">Oficio</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-tx-soft">Fecha recepción</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-tx-soft">Área solicitante</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-tx-soft">Elementos</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-tx-soft">Aptos</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-tx-soft">Descartados</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-tx-soft">Estado</th>
                        <th scope="col" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-tx-soft">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    @foreach ($solicitudes as $s)
                        <tr class="hover:bg-slate-50/60 transition">
                            <td class="px-4 py-3 text-xs font-semibold text-inst-900">
                                {{ $s->numero_tarjeta }}
                            </td>
                            <td class="px-4 py-3 text-xs font-medium text-tx">
                                {{ $s->numero_oficio }}
                            </td>
                            <td class="px-4 py-3 text-xs text-tx-soft">
                                {{ $s->fecha_recepcion ? $s->fecha_recepcion->format('d/m/Y') : '—' }}
                            </td>
                            <td class="px-4 py-3 text-xs text-tx max-w-[200px] truncate">
                                {{ $s->area_solicitante ?: '—' }}
                            </td>
                            <td class="px-4 py-3 text-xs font-bold text-inst-900">
                                {{ $s->total_elementos }}
                            </td>
                            <td class="px-4 py-3 text-xs font-semibold text-estado-exito">
                                {{ $s->total_aptos }}
                            </td>
                            <td class="px-4 py-3 text-xs font-semibold text-estado-error">
                                {{ $s->total_descartados }}
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <x-status-badge :label="ucfirst($s->estado_general)" :tone="$s->estado_general === 'enviada_impresion' ? 'success' : ($s->estado_general === 'evaluada' ? 'info' : 'warning')"/>
                            </td>
                            <td class="px-4 py-3 text-right text-xs">
                                <a href="{{ route('credencializacion.detalle', $s->id) }}" class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-inst-700 hover:bg-slate-50 hover:text-inst-900 transition">
                                    Abrir expediente
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

