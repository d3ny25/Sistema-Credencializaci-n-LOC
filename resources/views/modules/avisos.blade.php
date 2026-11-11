@extends('layouts.app')

@section('title', 'Avisos institucionales')
@section('heading', 'Avisos institucionales')
@section('subheading', 'Comunicados, notificaciones y pendientes del sistema')

@section('content')
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-inst-100 bg-inst-50/70 p-4">
        <div><p class="text-sm font-semibold text-inst-900">Centro de avisos</p><p class="mt-1 text-xs leading-5 text-tx-soft">Los avisos y las notificaciones inter-área requieren persistencia y permisos que aún no están configurados.</p></div>
        <button type="button" disabled title="No existe una operación de publicación conectada." class="rounded-md bg-slate-200 px-4 py-2 text-sm font-medium text-slate-500">Nuevo aviso · no disponible</button>
    </div>

    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
        @foreach (['Total de avisos', 'Sin leer', 'Prioridad alta'] as $label)
            <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-card"><p class="text-xs text-tx-soft">{{ $label }}</p><p class="mt-2 text-2xl font-semibold text-inst-900">—</p><p class="mt-1 text-xs text-tx-soft">Sin servicio de notificaciones</p></div>
        @endforeach
    </div>

    <x-card title="Avisos" description="Filtra por periodo, prioridad y estado. Los cambios de lectura no se registran hasta implementar el servicio.">
        <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <label class="text-xs font-medium text-tx-soft">Buscar<input type="search" disabled placeholder="Título o contenido" class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></label>
            <label class="text-xs font-medium text-tx-soft">Prioridad<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Todas</option><option>Alta</option><option>Media</option><option>Informativa</option></select></label>
            <label class="text-xs font-medium text-tx-soft">Estado<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Todos</option><option>Sin leer</option><option>Leído</option></select></label>
            <label class="text-xs font-medium text-tx-soft">Periodo<input type="month" disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></label>
        </div>
        <div class="mb-4 flex flex-wrap justify-end gap-2">
            <button type="button" disabled class="rounded-md border border-slate-200 px-4 py-2 text-sm font-medium text-slate-400">Marcar todos como leídos · no disponible</button>
            <button type="button" disabled class="rounded-md border border-slate-200 px-4 py-2 text-sm font-medium text-slate-400">Aplicar filtros · no disponible</button>
        </div>
        <x-empty-table :columns="['Título', 'Emisor', 'Área / audiencia', 'Fecha', 'Prioridad', 'Estado', 'Acciones']" message="No hay avisos disponibles; no se crean notificaciones de ejemplo."/>
    </x-card>
@endsection
