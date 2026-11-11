@extends('layouts.app')

@section('title', $detail ? 'Expediente del elemento' : 'Consulta general')
@section('heading', $detail ? 'Expediente del elemento' : 'Consulta general')
@section('subheading', 'Búsqueda autorizada y trazabilidad del expediente')

@section('content')
    @if ($detail)
        <div class="mb-5">
            <a href="{{ route('consultas') }}" class="text-sm font-medium text-inst-700 hover:text-inst-900">← Volver a consulta</a>
        </div>
        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
            El identificador solicitado no se resuelve a un expediente en esta vista. La autenticación y autorización para consultar datos personales todavía no están configuradas.
        </div>
        <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach (['Identidad', 'Adscripción', 'CUIP', 'Estatus LOC'] as $label)
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-card"><p class="text-xs text-tx-soft">{{ $label }}</p><p class="mt-2 text-sm font-medium text-tx">No consultado</p></div>
            @endforeach
        </div>
        <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
            <div class="space-y-5 xl:col-span-2">
                <x-card title="Información personal">
                    <x-empty-table :columns="['Dato', 'Valor']" message="Sin información personal autorizada."/>
                </x-card>
                <x-card title="Adscripción y situación laboral">
                    <x-empty-table :columns="['Unidad', 'Subdirección', 'Agrupamiento', 'Región', 'Municipio', 'Categoría', 'Estatus', 'Fecha de ingreso', 'Fecha de baja']"/>
                </x-card>
                <x-card title="Armamento asignado">
                    <x-empty-table :columns="['Tipo', 'Clase', 'Matrícula', 'Calibre', 'Marca', 'Modelo', 'Registro federal', 'Estatus de asignación', 'Fuente vigente']"/>
                </x-card>
                <x-card title="Credenciales y vigencias">
                    <x-empty-table :columns="['Tipo', 'Oficio', 'Tarjeta', 'Folio', 'Estado biométrico', 'Emisión', 'Entrega', 'Vigencia LOC']"/>
                </x-card>
                <x-card title="Biometría">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        @foreach (['Fotografía', 'Firma', 'Huellas'] as $item)
                            <div class="rounded-lg border border-dashed border-slate-300 p-4"><p class="text-sm font-medium text-inst-900">{{ $item }}</p><p class="mt-2 text-xs text-tx-soft">No almacenada o consultada en esta pantalla.</p></div>
                        @endforeach
                    </div>
                </x-card>
            </div>
            <div class="space-y-5">
                <x-card title="Historial de cambios" description="Las fuentes existentes mantienen antecedentes de actualización LOC.">
                    <x-empty-table :columns="['Fecha', 'Campo', 'Valor anterior', 'Valor nuevo', 'Motivo']"/>
                </x-card>
                <x-card title="Incidencias y observaciones" description="Solo se mostrarán anotaciones persistidas y autorizadas.">
                    <x-empty-table :columns="['Fecha', 'Tipo', 'Estado', 'Detalle']"/>
                </x-card>
            </div>
        </div>
    @else
        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
            La consulta queda deshabilitada hasta contar con autenticación y permisos de lectura de expedientes.
        </div>
        <x-card title="Criterios de búsqueda" description="La búsqueda prevista admite identificadores, persona, solicitud y estado LOC.">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach (['CUIP', 'Nombre(s) y apellidos', 'RFC', 'CURP', 'Número de tarjeta', 'Folio', 'Oficio', 'Municipio'] as $field)
                    <label class="text-xs font-medium text-tx-soft">{{ $field }}
                        <input type="search" disabled placeholder="Búsqueda no disponible" class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200">
                    </label>
                @endforeach
                <label class="text-xs font-medium text-tx-soft">Región<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Todas las regiones</option></select></label>
                <label class="text-xs font-medium text-tx-soft">Situación LOC<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Todas las situaciones</option></select></label>
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" disabled class="rounded-md border border-slate-200 px-4 py-2 text-sm font-medium text-slate-400">Limpiar · no disponible</button>
                <button type="button" disabled class="rounded-md bg-slate-200 px-4 py-2 text-sm font-medium text-slate-500">Buscar · no disponible</button>
            </div>
        </x-card>
        <div class="mt-5">
            <x-card title="Resultados" description="Los resultados autorizados incluirán acceso al expediente y coincidencias de tarjeta u oficio.">
                <x-empty-table :columns="['CUIP', 'Nombre', 'RFC', 'CURP', 'Municipio', 'Oficio', 'Trámite', 'Tarjeta', 'Folio', 'Situación LOC', 'Acciones']" message="Sin resultados; no se cargan datos de demostración."/>
            </x-card>
        </div>
    @endif
@endsection
