@extends('layouts.app')

@section('title', $device->name)
@section('heading', 'Administración')
@section('subheading', 'Ficha de dispositivo')

@section('content')
    @include('modules.devices._admin_tabs', ['active' => 'dispositivos'])

    <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-lg font-semibold text-inst-900">{{ $device->name }}</p>
            <p class="mt-1 text-sm text-tx-soft">{{ $type['label'] }} · {{ $device->manufacturer }} {{ $device->model }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('administracion.dispositivos') }}" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-tx hover:bg-slate-50">Volver al inventario</a>
            @can('manage-devices')
                <a href="{{ route('administracion.dispositivos.editar', $device) }}" class="rounded-md bg-inst-700 px-4 py-2 text-sm font-semibold text-white hover:bg-inst-900">Editar configuración</a>
            @endcan
        </div>
    </div>

    <div class="mb-5 grid grid-cols-1 gap-4 xl:grid-cols-2">
        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-inst-900">Identificación y configuración</h2>
            <dl class="mt-4 grid grid-cols-1 gap-x-5 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-xs text-tx-soft">Identificador interno</dt><dd class="mt-1 font-medium text-tx">{{ $device->identifier ?: '—' }}</dd></div>
                <div><dt class="text-xs text-tx-soft">Ubicación / estación</dt><dd class="mt-1 font-medium text-tx">{{ $device->location ?: '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-xs text-tx-soft">Método de conexión previsto</dt><dd class="mt-1 font-medium text-tx">{{ $type['connection_methods'][$device->connection_method] ?? $device->connection_method }}</dd></div>
                @forelse ($device->configuration ?? [] as $key => $value)
                    <div><dt class="text-xs text-tx-soft">{{ $type['configuration'][$key]['label'] ?? $key }}</dt><dd class="mt-1 font-medium text-tx">{{ is_scalar($value) ? $value : '—' }}</dd></div>
                @empty
                    <div class="sm:col-span-2"><dt class="text-xs text-tx-soft">Configuración específica</dt><dd class="mt-1 font-medium text-tx">No aplica configuración persistente adicional.</dd></div>
                @endforelse
            </dl>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-inst-900">Estados y diagnóstico</h2>
            <dl class="mt-4 grid grid-cols-1 gap-x-5 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-xs text-tx-soft">Estado administrativo</dt><dd class="mt-1 font-medium text-tx">{{ $statuses[$device->administrative_status] ?? $device->administrative_status }}</dd></div>
                <div><dt class="text-xs text-tx-soft">Conexión física</dt><dd class="mt-1 font-medium text-tx">No verificada por Laravel</dd></div>
                <div><dt class="text-xs text-tx-soft">Último diagnóstico</dt><dd class="mt-1 font-medium text-tx">{{ $device->last_checked_at?->format('d/m/Y H:i:s') ?? 'Sin ejecutar' }}</dd></div>
                <div><dt class="text-xs text-tx-soft">Resultado</dt><dd class="mt-1 font-medium text-tx">{{ $device->last_test_result === 'unavailable' ? 'No disponible' : 'Sin resultado' }}</dd></div>
                @if ($device->last_test_message)
                    <div class="sm:col-span-2"><dt class="text-xs text-tx-soft">Mensaje</dt><dd class="mt-1 leading-5 text-tx">{{ $device->last_test_message }}</dd></div>
                @endif
            </dl>

            <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                @can('test-devices')
                    <form method="POST" action="{{ route('administracion.dispositivos.diagnostico', $device) }}">
                        @csrf
                        <button type="submit" class="rounded-md border border-inst-700 px-3 py-2 text-sm font-semibold text-inst-700 hover:bg-inst-50">Registrar diagnóstico de disponibilidad</button>
                    </form>
                @endcan
                @can('administer-devices')
                    <form method="POST" action="{{ route('administracion.dispositivos.estado', $device) }}" class="flex flex-wrap items-center gap-2">
                        @csrf
                        @method('PATCH')
                        <label class="sr-only" for="admin-status">Estado administrativo</label>
                        <select id="admin-status" name="status" class="rounded-md border-slate-300 text-sm text-tx shadow-sm focus:border-inst-700 focus:ring-inst-700">
                            @foreach (['enabled' => 'Habilitado', 'disabled' => 'Deshabilitado', 'out_of_service' => 'Fuera de servicio'] as $key => $label)
                                <option value="{{ $key }}" @selected($device->administrative_status === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-tx hover:bg-slate-50">Actualizar estado</button>
                    </form>
                @endcan
            </div>

            @can('test-devices')
                <p class="mt-3 text-xs leading-5 text-tx-soft">El registro del diagnóstico explicará por qué el servidor no puede comprobar este equipo. No es una prueba física ni un resultado positivo.</p>
            @endcan
        </section>
    </div>

    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-inst-900">Historial de cambios y comprobaciones</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-tx-soft">
                    <tr><th class="px-4 py-3 font-semibold">Fecha</th><th class="px-4 py-3 font-semibold">Usuario</th><th class="px-4 py-3 font-semibold">Evento</th><th class="px-4 py-3 font-semibold">Resultado</th><th class="px-4 py-3 font-semibold">Mensaje</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($events as $event)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3">{{ $event->created_at->format('d/m/Y H:i:s') }}</td>
                            <td class="px-4 py-3">{{ $event->user?->name ?? 'Usuario eliminado' }}</td>
                            <td class="px-4 py-3">{{ match ($event->event_type) { 'registered' => 'Registro', 'updated' => 'Configuración actualizada', 'status_changed' => 'Cambio de estado', 'diagnostic' => 'Diagnóstico', default => $event->event_type } }}</td>
                            <td class="px-4 py-3">{{ $event->result === 'unavailable' ? 'No disponible' : '—' }}</td>
                            <td class="min-w-64 px-4 py-3">{{ $event->message }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-sm text-tx-soft">Sin eventos registrados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
