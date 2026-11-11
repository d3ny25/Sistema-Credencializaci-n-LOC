@extends('layouts.app')

@php
    $tabs = [
        ['key' => 'panel', 'label' => 'Panel'],
        ['key' => 'credenciales-recibidas', 'label' => 'Credenciales recibidas'],
        ['key' => 'pendientes', 'label' => 'Pendientes'],
        ['key' => 'entregas', 'label' => 'Entregas'],
        ['key' => 'retenidas', 'label' => 'Retenidas'],
        ['key' => 'confirmaciones', 'label' => 'Confirmaciones'],
        ['key' => 'conciliacion', 'label' => 'Conciliación LOC'],
        ['key' => 'historial', 'label' => 'Historial'],
    ];
    $activeTab = collect($tabs)->firstWhere('key', $section);
@endphp

@section('title', $activeTab['label'] . ' · Entrega y seguimiento')
@section('heading', 'Entrega y seguimiento')
@section('subheading', 'Validación previa, entregas físicas y conciliación LOC')

@section('content')
    <x-module-tabs :active="$section" :items="collect($tabs)->map(fn ($tab) => [
        'key' => $tab['key'],
        'label' => $tab['label'],
        'route' => 'entregas',
        'parameters' => ['section' => $tab['key']],
    ])->all()"/>

    <div class="mb-5 rounded-lg border border-inst-100 bg-inst-50/70 p-4 text-sm leading-6 text-inst-900">
        Las ocho secciones reflejan el flujo visual previsto. No registran recepciones, entregas, confirmaciones ni conciliaciones; los datos personales no se exponen hasta habilitar autenticación.
    </div>

    @switch($section)
        @case('panel')
            <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach (['Credenciales recibidas', 'Pendientes de entrega', 'Entregas parciales', 'Credenciales retenidas'] as $label)
                    <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-card"><p class="text-xs text-tx-soft">{{ $label }}</p><p class="mt-2 text-2xl font-semibold text-inst-900">—</p><p class="mt-1 text-xs text-tx-soft">Sin consulta autorizada</p></div>
                @endforeach
            </div>
            <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
                <x-card title="Avance por tarjeta" description="No se calcula progreso sin recepciones y entregas persistidas.">
                    <ol class="space-y-3">
                        @foreach (['Recepción desde Impresión', 'Validación contra LOC', 'Preparación de entrega', 'Entrega física', 'Confirmación municipal'] as $step)
                            <li class="flex items-center gap-3 rounded-lg border border-slate-200 p-3"><span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-tx-soft">—</span><span class="text-sm text-tx">{{ $step }}</span><x-status-badge class="ml-auto" label="Sin datos" tone="neutral"/></li>
                        @endforeach
                    </ol>
                </x-card>
                <div class="xl:col-span-2">
                    <x-card title="Secuencia del proceso">
                        <ol class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                            @foreach ([
                                ['Validación LOC', 'Verificar vigencia y situación del elemento antes de entregar.'],
                                ['Recepción de credenciales', 'Conciliar lo recibido con los registros de emisión.'],
                                ['Entrega física', 'Registrar persona receptora, responsable, fecha y entrega parcial.'],
                                ['Confirmación municipal', 'Conservar acuse o respuesta de confirmación.'],
                            ] as [$step, $description])
                                <li class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                                    <span class="font-medium text-inst-900">{{ $step }}</span>
                                    <p class="mt-1 text-xs leading-5 text-tx-soft">{{ $description }}</p>
                                    <p class="mt-2 text-xs font-medium text-estado-adv">Operación no disponible</p>
                                </li>
                            @endforeach
                        </ol>
                    </x-card>
                </div>
            </div>
            <div class="mt-5">
                <x-card title="Pendientes de atención" description="Sin una bitácora autorizada no se muestran actividades ni compromisos.">
                    <x-empty-table :columns="['Tarjeta', 'Municipio / área', 'Etapa', 'Fecha', 'Responsable', 'Acción']"/>
                </x-card>
            </div>
            @break
        @case('credenciales-recibidas')
            <x-card title="Credenciales recibidas" description="Bandeja de recepción de credenciales emitidas por Impresión.">
                <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <label class="text-xs font-medium text-tx-soft">Buscar<input type="search" disabled placeholder="Oficio, tarjeta o folio" class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></label>
                    <label class="text-xs font-medium text-tx-soft">Estado de recepción<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Todos los estados</option><option>Pendiente</option><option>Recibida</option></select></label>
                    <label class="text-xs font-medium text-tx-soft">Periodo<input type="month" disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></label>
                </div>
                <div class="mb-4 flex justify-end"><button type="button" disabled title="La recepción requiere cotejo de registros." class="rounded-md bg-slate-200 px-4 py-2 text-sm font-medium text-slate-500">Registrar recepción · no disponible</button></div>
                <x-empty-table :columns="['Oficio', 'Tarjeta', 'Folio', 'CUIP', 'Nombre', 'Municipio', 'Fecha de emisión', 'Fecha de recepción', 'Estado', 'Acciones']"/>
            </x-card>
            @break
        @case('pendientes')
            <x-card title="Pendientes de entrega" description="La situación LOC debe comprobarse antes de cualquier entrega.">
                <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-4">
                    <label class="text-xs font-medium text-tx-soft">Buscar<input type="search" disabled placeholder="CUIP, nombre, tarjeta o folio" class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></label>
                    <label class="text-xs font-medium text-tx-soft">Municipio<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Todos</option></select></label>
                    <label class="text-xs font-medium text-tx-soft">Estado LOC<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Todos</option><option>Vigente</option><option>Situación especial</option></select></label>
                    <label class="text-xs font-medium text-tx-soft">Estado de entrega<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Pendientes</option></select></label>
                </div>
                <div class="mb-4 flex justify-end"><button type="button" disabled title="No se habilita una entrega sin validación vigente." class="rounded-md bg-slate-200 px-4 py-2 text-sm font-medium text-slate-500">Preparar entrega · no disponible</button></div>
                <x-empty-table :columns="['CUIP', 'Nombre', 'Municipio / adscripción', 'Tarjeta', 'Folio', 'Situación LOC', 'Vigencia', 'Estado de entrega', 'Acción']"/>
            </x-card>
            @break
        @case('entregas')
            <x-card title="Entregas físicas" description="El registro deberá identificar receptor, responsable institucional, fecha y detalle de cada credencial.">
                <div class="mb-4 flex flex-wrap justify-end gap-2">
                    <label class="text-xs font-medium text-tx-soft">Estado<select disabled class="ml-2 rounded-md border-slate-300 text-sm"><option>Todos los estados</option><option>Parcial</option><option>Completa</option></select></label>
                    <button type="button" disabled title="El registro de entregas aún no está disponible." class="rounded-md bg-slate-200 px-4 py-2 text-sm font-medium text-slate-500">Nueva entrega · no disponible</button>
                </div>
                <x-empty-table :columns="['Número de entrega', 'Oficio', 'Área solicitante', 'Fecha', 'Credenciales', 'Receptor', 'Responsable institucional', 'Estado', 'Acciones']"/>
            </x-card>
            @break
        @case('retenidas')
            <x-card title="Credenciales retenidas" description="La retención requiere motivo documentado y seguimiento antes de liberar una credencial.">
                <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <label class="text-xs font-medium text-tx-soft">Buscar<input type="search" disabled placeholder="CUIP, tarjeta o folio" class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></label>
                    <label class="text-xs font-medium text-tx-soft">Motivo<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Todos los motivos</option></select></label>
                    <label class="text-xs font-medium text-tx-soft">Estado de seguimiento<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Todos los estados</option></select></label>
                </div>
                <x-empty-table :columns="['CUIP', 'Nombre', 'Municipio', 'Tarjeta', 'Folio', 'Motivo', 'Fecha de detección', 'Último seguimiento', 'Estado', 'Acciones']"/>
            </x-card>
            @break
        @case('confirmaciones')
            <x-card title="Confirmaciones" description="Acuses y respuestas municipales; no se simulan confirmaciones ni archivos recibidos.">
                <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <label class="text-xs font-medium text-tx-soft">Buscar<input type="search" disabled placeholder="Tarjeta o municipio" class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></label>
                    <label class="text-xs font-medium text-tx-soft">Estado<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Todas</option><option>Pendiente</option><option>Confirmada</option></select></label>
                    <label class="text-xs font-medium text-tx-soft">Periodo<input type="month" disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></label>
                </div>
                <x-empty-table :columns="['Tarjeta', 'Oficio', 'Municipio', 'Fecha de entrega', 'Fecha de confirmación', 'Persona receptora', 'Área', 'Acuse / archivo', 'Estado', 'Acciones']"/>
            </x-card>
            @break
        @case('conciliacion')
            <x-card title="Conciliación contra LOC" description="La conciliación requiere fuente LOC vigente, reglas aprobadas y persistencia de diferencias.">
                <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <label class="text-xs font-medium text-tx-soft">Fuente LOC<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Selecciona una importación</option></select></label>
                    <label class="text-xs font-medium text-tx-soft">Resultado<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Todos los resultados</option><option>Coincide</option><option>Discrepancia</option></select></label>
                    <label class="text-xs font-medium text-tx-soft">Buscar<input type="search" disabled placeholder="CUIP, tarjeta o folio" class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></label>
                </div>
                <div class="mb-4 flex justify-end"><button type="button" disabled title="No se inicia una conciliación sin reglas y fuentes verificadas." class="rounded-md bg-slate-200 px-4 py-2 text-sm font-medium text-slate-500">Conciliar · no disponible</button></div>
                <x-empty-table :columns="['CUIP', 'Nombre', 'Municipio', 'Tarjeta', 'Folio', 'Credencial', 'Situación LOC', 'Vigencia LOC', 'Resultado', 'Discrepancia']"/>
            </x-card>
            @break
        @case('historial')
            <x-card title="Historial de seguimiento" description="Consulta cronológica de eventos de recepción, entrega, retención, confirmación y conciliación.">
                <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-4">
                    <label class="text-xs font-medium text-tx-soft">Desde<input type="date" disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></label>
                    <label class="text-xs font-medium text-tx-soft">Hasta<input type="date" disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></label>
                    <label class="text-xs font-medium text-tx-soft">Tipo de evento<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Todos los eventos</option></select></label>
                    <label class="text-xs font-medium text-tx-soft">Tarjeta o CUIP<input type="search" disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></label>
                </div>
                <x-empty-table :columns="['Fecha y hora', 'CUIP', 'Nombre', 'Tarjeta', 'Folio', 'Evento', 'Usuario', 'Área', 'Observaciones']"/>
            </x-card>
            @break
    @endswitch
@endsection
