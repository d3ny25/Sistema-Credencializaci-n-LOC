@extends('layouts.app')

@section('title', 'Reportes')
@section('heading', 'Reportes')
@section('subheading', 'Reportes por periodo, estado y módulo')

@section('content')
    <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
        Los filtros y exportaciones requieren una fuente autorizada y una consulta validada. No se generan archivos vacíos ni resultados de demostración.
    </div>

    <x-card title="Generador de reportes" description="Selecciona una plantilla y criterios de periodo, estado y ámbito. Las operaciones permanecen inactivas hasta conectarse a datos autorizados.">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <label class="text-xs font-medium text-tx-soft">Plantilla de reporte
                <select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200">
                    <option>Selecciona una plantilla</option>
                    <option>Solicitudes por estado</option>
                    <option>Elementos aptos y descartados</option>
                    <option>Credenciales emitidas y entregadas</option>
                    <option>Vigencias y situaciones LOC</option>
                    <option>Importaciones y validación</option>
                </select>
            </label>
            <label class="text-xs font-medium text-tx-soft">Módulo
                <select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Todos los módulos</option><option>Gestión de información</option><option>Credencialización</option><option>Impresión</option><option>Entrega y seguimiento</option></select>
            </label>
            <label class="text-xs font-medium text-tx-soft">Estado
                <select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Todos los estados</option><option>Pendiente</option><option>En proceso</option><option>Completo</option><option>Retenido</option></select>
            </label>
            <label class="text-xs font-medium text-tx-soft">Municipio / región
                <select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Todos</option></select>
            </label>
            <label class="text-xs font-medium text-tx-soft">Fecha inicial<input type="date" disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></label>
            <label class="text-xs font-medium text-tx-soft">Fecha final<input type="date" disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></label>
            <label class="text-xs font-medium text-tx-soft">Agrupación
                <select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Sin agrupar</option><option>Por municipio</option><option>Por región</option><option>Por estado</option></select>
            </label>
            <label class="text-xs font-medium text-tx-soft">Formato
                <select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Selecciona formato</option><option>PDF</option><option>Excel</option></select>
            </label>
        </div>
        <div class="mt-5 flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4">
            <button type="button" disabled class="rounded-md border border-slate-200 px-4 py-2 text-sm font-medium text-slate-400">Limpiar filtros · no disponible</button>
            <button type="button" disabled title="La generación requiere consulta de datos autorizada." class="rounded-md bg-slate-200 px-4 py-2 text-sm font-medium text-slate-500">Generar vista previa · no disponible</button>
            <button type="button" disabled title="No se exporta un reporte vacío o no generado." class="rounded-md border border-slate-200 px-4 py-2 text-sm font-medium text-slate-400">Exportar PDF · no disponible</button>
            <button type="button" disabled title="No se exporta un reporte vacío o no generado." class="rounded-md border border-slate-200 px-4 py-2 text-sm font-medium text-slate-400">Exportar Excel · no disponible</button>
        </div>
    </x-card>

    <div class="mt-5">
        <x-card title="Vista previa" description="La estructura mantiene el espacio para resultados, periodo consultado y totales.">
            <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="rounded-lg bg-slate-50 p-4"><p class="text-xs text-tx-soft">Plantilla</p><p class="mt-1 text-sm font-medium text-tx">Sin seleccionar</p></div>
                <div class="rounded-lg bg-slate-50 p-4"><p class="text-xs text-tx-soft">Periodo</p><p class="mt-1 text-sm font-medium text-tx">Sin consultar</p></div>
                <div class="rounded-lg bg-slate-50 p-4"><p class="text-xs text-tx-soft">Registros</p><p class="mt-1 text-sm font-medium text-tx">—</p></div>
            </div>
            <x-empty-table :columns="['Concepto', 'Municipio / región', 'Periodo', 'Cantidad', 'Estado', 'Observaciones']" message="Selecciona una plantilla cuando la consulta esté disponible."/>
        </x-card>
    </div>
    <div class="mt-5">
        <x-card title="Historial de exportaciones" description="No hay un servicio de generación o almacenamiento de reportes conectado.">
            <x-empty-table :columns="['Reporte', 'Periodo', 'Formato', 'Fecha', 'Usuario', 'Estado']"/>
        </x-card>
    </div>
@endsection
