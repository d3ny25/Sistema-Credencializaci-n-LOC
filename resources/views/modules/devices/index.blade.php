@extends('layouts.app')

@section('title', 'Gestión de dispositivos')
@section('heading', 'Administración')
@section('subheading', 'Inventario, configuración e historial de los equipos CPAF')

@section('content')
    @include('modules.devices._admin_tabs', ['active' => 'dispositivos'])

    <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm leading-6 text-amber-950">
        El servidor Laravel no tiene acceso directo al USB, a las carpetas locales, a SigWeb ni al controlador Zebra de cada estación. Los diagnósticos desde aquí se registran como <strong>no disponibles</strong>; no comprueban la conexión física ni envían trabajos de impresión.
    </div>

    <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['label' => 'Dispositivos registrados', 'value' => $summary['total']],
            ['label' => 'Habilitados', 'value' => $summary['enabled']],
            ['label' => 'Pendientes de verificación', 'value' => $summary['pending']],
            ['label' => 'Fuera de servicio', 'value' => $summary['out_of_service']],
        ] as $metric)
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <p class="text-xs font-medium text-tx-soft">{{ $metric['label'] }}</p>
                <p class="mt-2 text-2xl font-semibold text-inst-900">{{ number_format($metric['value']) }}</p>
            </div>
        @endforeach
    </div>

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" action="{{ route('administracion.dispositivos') }}" class="grid w-full grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label class="text-xs font-medium text-tx-soft">
                Búsqueda
                <input type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Nombre, identificador o modelo"
                       class="mt-1 block w-full rounded-md border-slate-300 text-sm text-tx shadow-sm focus:border-inst-700 focus:ring-inst-700">
            </label>
            <label class="text-xs font-medium text-tx-soft">
                Tipo
                <select name="type" class="mt-1 block w-full rounded-md border-slate-300 text-sm text-tx shadow-sm focus:border-inst-700 focus:ring-inst-700">
                    <option value="">Todos</option>
                    @foreach ($types as $key => $type)
                        <option value="{{ $key }}" @selected(($filters['type'] ?? '') === $key)>{{ $type['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-xs font-medium text-tx-soft">
                Estado administrativo
                <select name="status" class="mt-1 block w-full rounded-md border-slate-300 text-sm text-tx shadow-sm focus:border-inst-700 focus:ring-inst-700">
                    <option value="">Todos</option>
                    @foreach ($statuses as $key => $label)
                        <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-xs font-medium text-tx-soft">
                Diagnóstico del servidor
                <select name="connection" class="mt-1 block w-full rounded-md border-slate-300 text-sm text-tx shadow-sm focus:border-inst-700 focus:ring-inst-700">
                    <option value="">Todos</option>
                    <option value="not_tested" @selected(($filters['connection'] ?? '') === 'not_tested')>Sin diagnóstico</option>
                    <option value="unavailable" @selected(($filters['connection'] ?? '') === 'unavailable')>No disponible desde Laravel</option>
                </select>
            </label>
            <div class="flex gap-2 sm:col-span-2 lg:col-span-4">
                <button type="submit" class="rounded-md bg-inst-700 px-4 py-2 text-sm font-semibold text-white hover:bg-inst-900">Filtrar</button>
                <a href="{{ route('administracion.dispositivos') }}" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-tx hover:bg-slate-50">Limpiar</a>
                @can('manage-devices')
                    <a href="{{ route('administracion.dispositivos.crear') }}" class="ml-auto rounded-md bg-inst-700 px-4 py-2 text-sm font-semibold text-white hover:bg-inst-900">Registrar dispositivo</a>
                @endcan
            </div>
        </form>
    </div>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-tx-soft">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Dispositivo</th>
                        <th class="px-4 py-3 font-semibold">Tipo</th>
                        <th class="px-4 py-3 font-semibold">Fabricante / modelo</th>
                        <th class="px-4 py-3 font-semibold">Identificador</th>
                        <th class="px-4 py-3 font-semibold">Ubicación</th>
                        <th class="px-4 py-3 font-semibold">Estado administrativo</th>
                        <th class="px-4 py-3 font-semibold">Diagnóstico del servidor</th>
                        <th class="px-4 py-3 font-semibold">Última comprobación</th>
                        <th class="px-4 py-3 font-semibold">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($devices as $device)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-inst-900">{{ $device->name }}</td>
                            <td class="px-4 py-3">{{ $types[$device->type]['label'] ?? $device->type }}</td>
                            <td class="px-4 py-3">{{ $device->manufacturer }}<span class="block text-xs text-tx-soft">{{ $device->model }}</span></td>
                            <td class="px-4 py-3">{{ $device->identifier ?: '—' }}</td>
                            <td class="px-4 py-3">{{ $device->location ?: '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-tx">{{ $statuses[$device->administrative_status] ?? $device->administrative_status }}</span>
                            </td>
                            <td class="px-4 py-3">{{ $device->last_test_result === 'unavailable' ? 'No disponible desde Laravel' : 'Sin verificar' }}</td>
                            <td class="whitespace-nowrap px-4 py-3">{{ $device->last_checked_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <a href="{{ route('administracion.dispositivos.detalle', $device) }}" class="font-medium text-inst-700 hover:text-inst-900">Ver detalle</a>
                                @can('manage-devices')
                                    <a href="{{ route('administracion.dispositivos.editar', $device) }}" class="ml-3 font-medium text-inst-700 hover:text-inst-900">Editar</a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-10 text-center text-sm text-tx-soft">No hay dispositivos registrados con estos filtros.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-200 px-4 py-3">{{ $devices->links() }}</div>
    </div>
@endsection
