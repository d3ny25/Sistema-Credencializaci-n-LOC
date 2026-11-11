@extends('layouts.app')

@section('title', 'Administración')
@section('heading', 'Administración')
@section('subheading', 'Usuarios, roles, catálogos, diseño de credencial, dispositivos y actividad del sistema')

@section('content')
    @include('modules.devices._admin_tabs', ['active' => $section])

    <div class="mb-5 rounded-lg border border-inst-100 bg-inst-50/70 p-4 text-sm leading-6 text-inst-900">
        Las pantallas administrativas conservan su estructura, pero sus acciones están deshabilitadas: no hay un flujo de autenticación o persistencia para usuarios, roles, catálogos, avisos o bitácora. La Gestión de dispositivos requiere una sesión autenticada y el permiso de consulta correspondiente.
    </div>

    @switch($section)
        @case('usuarios')
            <div class="mb-4 flex flex-wrap justify-end gap-2">
                <button type="button" disabled title="No se crean cuentas sin autenticación y gestión de contraseñas." class="rounded-md bg-slate-200 px-4 py-2 text-sm font-medium text-slate-500">Agregar usuario · no disponible</button>
            </div>
            <x-card title="Usuarios" description="Directorio institucional previsto; no se muestran cuentas ni credenciales de ejemplo.">
                <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <label class="text-xs font-medium text-tx-soft">Buscar<input type="search" disabled placeholder="Nombre o usuario" class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></label>
                    <label class="text-xs font-medium text-tx-soft">Área<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Todas las áreas</option></select></label>
                    <label class="text-xs font-medium text-tx-soft">Estado<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Todos</option><option>Activo</option><option>Inactivo</option></select></label>
                </div>
                <x-empty-table :columns="['Nombre', 'Usuario / correo', 'Área', 'Rol', 'Estado', 'Último acceso', 'Acciones']"/>
            </x-card>
            @break
        @case('roles')
            <x-card title="Matriz de roles y permisos" description="La definición de roles y autorización debe validarse en servidor; los controles son solo representativos.">
                <div class="mb-4 flex flex-wrap justify-end gap-2">
                    <button type="button" disabled title="La actualización de permisos debe realizarse mediante una política segura del servidor." class="rounded-md bg-slate-200 px-4 py-2 text-sm font-medium text-slate-500">Guardar matriz · no disponible</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-tx-soft"><tr><th class="px-4 py-3">Módulo</th><th class="px-4 py-3">Rol</th><th class="px-4 py-3">Consultar</th><th class="px-4 py-3">Crear</th><th class="px-4 py-3">Actualizar</th><th class="px-4 py-3">Autorizar</th></tr></thead>
                        <tbody><tr><td colspan="6" class="px-4 py-10 text-center text-sm text-tx-soft">Sin matriz configurada. No se asignan roles de demostración.</td></tr></tbody>
                    </table>
                </div>
                <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach (['Consulta', 'Captura', 'Validación', 'Administración'] as $permission)
                        <label class="flex items-center gap-2 rounded-lg border border-slate-200 p-3 text-xs text-tx-soft"><input type="checkbox" disabled>{{ $permission }} · no asignado</label>
                    @endforeach
                </div>
            </x-card>
            @break
        @case('catalogos')
            <x-card title="Catálogos operativos" description="Se muestran únicamente las familias de catálogos existentes en las fuentes institucionales.">
                <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <label class="text-xs font-medium text-tx-soft">Tipo de catálogo<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Todos los catálogos</option><option>Estructura organizacional</option><option>Municipios y regiones</option><option>Categorías</option></select></label>
                    <label class="text-xs font-medium text-tx-soft">Estado<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Todos los estados</option></select></label>
                    <label class="text-xs font-medium text-tx-soft">Buscar<input type="search" disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></label>
                </div>
                <div class="mb-4 flex justify-end"><button type="button" disabled title="No se editan catálogos sin autorización institucional." class="rounded-md bg-slate-200 px-4 py-2 text-sm font-medium text-slate-500">Agregar registro · no disponible</button></div>
                <x-empty-table :columns="['Catálogo', 'Clave', 'Descripción', 'Jerarquía', 'Registros', 'Estado', 'Última actualización', 'Acciones']"/>
            </x-card>
            @break
        @case('avisos')
            <x-card title="Avisos institucionales" description="Los avisos se administrarán cuando exista persistencia, autorización de publicación y un registro de cambios.">
                <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label class="text-xs font-medium text-tx-soft">Título<input type="text" disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></label>
                    <label class="text-xs font-medium text-tx-soft">Audiencia<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Selecciona audiencia</option></select></label>
                    <label class="text-xs font-medium text-tx-soft">Prioridad<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Informativa</option><option>Media</option><option>Alta</option></select></label>
                    <label class="text-xs font-medium text-tx-soft">Vigencia<input type="date" disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></label>
                    <label class="text-xs font-medium text-tx-soft sm:col-span-2">Contenido<textarea rows="3" disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></textarea></label>
                </div>
                <div class="mb-4 flex justify-end"><button type="button" disabled class="rounded-md bg-slate-200 px-4 py-2 text-sm font-medium text-slate-500">Publicar aviso · no disponible</button></div>
                <x-empty-table :columns="['Título', 'Emisor', 'Audiencia', 'Prioridad', 'Vigencia', 'Fecha', 'Estado', 'Acciones']"/>
            </x-card>
            @break
        @case('bitacora')
            <x-card title="Bitácora de actividad" description="La bitácora transversal requiere captura persistente de eventos y autenticación.">
                <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <label class="text-xs font-medium text-tx-soft">Desde<input type="date" disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></label>
                    <label class="text-xs font-medium text-tx-soft">Hasta<input type="date" disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></label>
                    <label class="text-xs font-medium text-tx-soft">Módulo<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Todos los módulos</option></select></label>
                    <label class="text-xs font-medium text-tx-soft">Usuario o acción<input type="search" disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></label>
                </div>
                <div class="mb-4 flex justify-end"><button type="button" disabled class="rounded-md border border-slate-200 px-4 py-2 text-sm font-medium text-slate-400">Exportar bitácora · no disponible</button></div>
                <x-empty-table :columns="['Fecha y hora', 'Usuario', 'Área', 'Módulo', 'Acción', 'Registro afectado', 'Resultado', 'Descripción']"/>
            </x-card>
            @break
    @endswitch
@endsection
