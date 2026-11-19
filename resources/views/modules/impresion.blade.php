@extends('layouts.app')

@section('title', 'Impresión')
@section('heading', 'Impresión de credenciales')
@section('subheading', 'Cola de impresión y seguimiento biométrico')

@php
    try {
        $tarjetasDisponibles = \App\Models\SolicitudCredencial::query()
            ->where('estado_general', 'enviada_impresion')
            ->orderByDesc('id')
            ->get(['id', 'numero_tarjeta', 'numero_oficio', 'area_solicitante', 'total_aptos']);
    } catch (\Throwable) {
        $tarjetasDisponibles = collect();
    }
@endphp

@section('page-actions')
    @can('manage-credential-templates')
        <span class="hidden text-xs text-tx-soft xl:inline">Diseño de credencial:</span>
        <a href="{{ route('impresion.plantilla', ['template_version' => 1]) }}" class="btn btn-secondary btn-sm" title="Editor de plantilla vertical · versión base">Editar v1 Base</a>
        <a href="{{ route('impresion.plantilla', ['template_version' => 2]) }}" class="btn btn-primary btn-sm" title="Editor de plantilla vertical · versión oficial"><x-icon name="pen" class="h-3.5 w-3.5"/>Editar v2 Oficial Real</a>
    @endcan
@endsection

@section('content')
    <x-module-tabs :active="$section" :items="[
        ['key' => 'cola', 'label' => 'Cola de impresión', 'route' => 'impresion'],
        ['key' => 'biometricos', 'label' => 'Pendientes biométricos', 'route' => 'impresion.biometricos'],
    ]"/>

    @if ($section === 'biometricos')
        <x-card title="Pendientes biométricos" icon="fingerprint" description="Elementos con fotografía, firma o huellas faltantes, o con ámbito no resuelto.">
            <div class="flex flex-col items-center px-6 py-10 text-center">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-inst-50 text-inst-700"><x-icon name="fingerprint" class="h-6 w-6"/></span>
                <h2 class="mt-3 text-sm font-semibold text-inst-900">La validación biométrica se realiza por tarjeta de trabajo</h2>
                <p class="mt-1 max-w-lg text-sm text-tx-soft">Seleccione una tarjeta en la cola de impresión y ejecute <strong>Validar datos biométricos</strong>: el semáforo de cada elemento indica qué archivos faltan. Este resumen transversal aún no tiene una consulta propia, por lo que no se muestran cifras.</p>
                <a href="{{ route('impresion') }}" class="btn btn-primary mt-5"><x-icon name="printer" class="h-4 w-4"/>Ir a la cola de impresión</a>
            </div>
        </x-card>
    @else
        {{-- Componente Reactivo Alpine.js de la Cola de Impresión --}}
        <div x-data="printQueue()" x-cloak class="space-y-5">
            <!-- 1. Tarjetas de Métricas / KPIs Reactivas -->
            <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
                <x-kpi label="Trabajos en cola" icon="inbox" tone="info">
                    <span x-text="metricas.trabajos_en_cola">0</span>
                    <x-slot:detail><span x-text="selectedTarjeta ? 'Elementos aptos en tarjeta' : 'Sin tarjeta seleccionada'"></span></x-slot:detail>
                </x-kpi>
                <x-kpi label="Listos para imprimir" icon="printer" tone="success">
                    <span x-text="metricas.listos_para_imprimir">0</span>
                    <x-slot:detail><span x-text="selectedTarjeta ? 'Con biometría 4/4 y folio' : 'Sin tarjeta seleccionada'"></span></x-slot:detail>
                </x-kpi>
                <x-kpi label="Biometría pendiente" icon="fingerprint" tone="warning">
                    <span x-text="metricas.biometria_pendiente">0</span>
                    <x-slot:detail><span x-text="selectedTarjeta ? 'Faltan archivos o ámbito' : 'Sin tarjeta seleccionada'"></span></x-slot:detail>
                </x-kpi>
                <x-kpi label="Impresos / emitidos" icon="creditcard" tone="neutral">
                    <span x-text="metricas.impresos">0</span>
                    <x-slot:detail><span x-text="selectedTarjeta ? 'Credenciales producidas' : 'Sin tarjeta seleccionada'"></span></x-slot:detail>
                </x-kpi>
            </div>

            <!-- 2. Banners de Alerta Reactivos -->
            <div x-show="mensajeExito" x-cloak role="status" class="alert alert-success justify-between">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <span x-text="mensajeExito"></span>
                </div>
                <button type="button" @click="mensajeExito = ''" class="rounded p-0.5 text-emerald-800 hover:bg-emerald-100" aria-label="Cerrar aviso"><x-icon name="x" class="h-4 w-4"/></button>
            </div>

            <div x-show="mensajeError" x-cloak role="alert" class="alert alert-danger justify-between">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-rose-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span x-text="mensajeError"></span>
                </div>
                <button type="button" @click="mensajeError = ''" class="rounded p-0.5 text-red-800 hover:bg-red-100" aria-label="Cerrar aviso"><x-icon name="x" class="h-4 w-4"/></button>
            </div>

            <!-- 3. Contenedor de la Cola de Impresión -->
            <x-card title="Cola de impresión" description="Seleccione una tarjeta de trabajo para consultar sus elementos aptos y validar la biometría oficial.">
                <!-- Filtros y Selectores -->
                <div class="panel-muted mb-4 grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 xl:grid-cols-4" role="search" aria-label="Filtrar cola de impresión">
                    <!-- Selector de Tarjeta de Trabajo -->
                    <label class="field-label">
                        Tarjeta de trabajo
                        <select data-tarjeta-trabajo-impresion
                                x-model="selectedTarjeta"
                                @change="cargarElementos()"
                                :disabled="isLoading">
                            <option value="">-- Selecciona una tarjeta de trabajo --</option>
                            @foreach ($tarjetasDisponibles as $tarjeta)
                                <option value="{{ $tarjeta->id }}">
                                    {{ $tarjeta->numero_tarjeta }} · Oficio {{ $tarjeta->numero_oficio }} ({{ $tarjeta->total_aptos }} aptos)
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <!-- Búsqueda reactiva -->
                    <label class="field-label">
                        Buscar en tarjeta
                        <input type="search"
                               x-model="search"
                               :disabled="!selectedTarjeta || isLoading"
                               placeholder="CUIP, nombre o folio">
                    </label>

                    <!-- Filtro de Estado Biométrico -->
                    <label class="field-label">
                        Estado biométrico
                        <select x-model="filtroEstado"
                                :disabled="!selectedTarjeta || isLoading">
                            <option value="todos">Todos los estados</option>
                            <option value="listo">Listos para imprimir (4/4)</option>
                            <option value="pendiente">Pendientes de biometría</option>
                        </select>
                    </label>

                    <!-- Filtro de Ámbito Institucional -->
                    <label class="field-label">
                        Ámbito institucional
                        <select x-model="filtroAmbito"
                                :disabled="!selectedTarjeta || isLoading">
                            <option value="todos">Todos los ámbitos</option>
                            <option value="ESTATALES">Estatales</option>
                            <option value="PREVENCION">Prevención</option>
                            <option value="NO_RESUELTO">No Resuelto</option>
                        </select>
                    </label>
                </div>

                <!-- Barra de Acciones y Contadores -->
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
                    <div class="text-xs text-tx-soft">
                        <template x-if="selectedTarjeta && elementos.length > 0">
                            <span>
                                Mostrando <strong class="text-tx" x-text="elementosFiltrados.length"></strong> de <strong class="text-tx" x-text="elementos.length"></strong> elementos aptos
                                <template x-if="numeroTarjeta"><span> · Tarjeta <span class="code-chip" x-text="numeroTarjeta"></span></span></template>
                            </span>
                        </template>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Botón Refrescar -->
                        <button type="button"
                                @click="cargarElementos()"
                                :disabled="isLoading || !selectedTarjeta"
                                class="btn btn-secondary">
                            <svg class="h-4 w-4 text-slate-500" :class="isLoading ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                            <span>Refrescar</span>
                        </button>

                        <!-- Botón Validar Biometría -->
                        <button type="button"
                                @click="validarBiometria()"
                                x-bind:disabled="isLoading || !selectedTarjeta"
                                class="btn btn-primary">
                            <svg x-show="isLoading" class="h-4 w-4 animate-spin text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <svg x-show="!isLoading" class="h-4 w-4 text-inst-100" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                            </svg>
                            <span x-text="isLoading ? 'Validando...' : 'Validar datos biométricos'">Validar datos biométricos</span>
                        </button>
                    </div>
                </div>

                <!-- 4. Contenedor de la Tabla de Elementos -->
                <div class="table-wrap">
                    <!-- Estado Vacío: Sin selección de tarjeta -->
                    <template x-if="!selectedTarjeta">
                        <div class="p-12 text-center">
                            <svg class="mx-auto h-12 w-12 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                            </svg>
                            <h3 class="mt-2 text-sm font-semibold text-slate-800">No hay tarjeta de trabajo seleccionada</h3>
                            <p class="mt-1 text-xs text-tx-soft">Seleccione una tarjeta en el menú superior para cargar sus elementos aptos.</p>
                        </div>
                    </template>

                    <!-- Estado Vacío: Tarjeta sin elementos aptos -->
                    <template x-if="selectedTarjeta && elementos.length === 0 && !isLoading">
                        <div class="p-12 text-center">
                            <svg class="mx-auto h-12 w-12 text-amber-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            <h3 class="mt-2 text-sm font-semibold text-slate-800">Sin elementos aptos</h3>
                            <p class="mt-1 text-xs text-tx-soft">La tarjeta seleccionada no cuenta con registros dictaminados como aptos por el prefiltro.</p>
                        </div>
                    </template>

                    <!-- Estado de Carga Inicial -->
                    <template x-if="isLoading && elementos.length === 0">
                        <div class="p-12 text-center">
                            <svg class="mx-auto h-8 w-8 animate-spin text-inst-700" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <p class="mt-2 text-sm font-medium text-slate-700">Cargando elementos de la tarjeta...</p>
                        </div>
                    </template>

                    @php
                        // Los cuatro componentes que evalúa la validación 4/4 (BiometricoExpediente::evaluarArchivoTecnico).
                        $componentesBio = [
                            ['key' => 'foto', 'label' => 'Fotografía', 'icon' => 'camera', 'lado' => null],
                            ['key' => 'firma', 'label' => 'Firma', 'icon' => 'pen', 'lado' => null],
                            ['key' => 'pulgar_derecho', 'label' => 'Pulgar derecho', 'icon' => 'fingerprint', 'lado' => 'D'],
                            ['key' => 'pulgar_izquierdo', 'label' => 'Pulgar izquierdo', 'icon' => 'fingerprint', 'lado' => 'I'],
                        ];
                    @endphp
                    <!-- Tabla Reactiva con x-for -->
                    <table x-show="selectedTarjeta && elementos.length > 0" class="table-data table-compact">
                        <thead>
                            <tr>
                                <th scope="col">CUIP / fila</th>
                                <th scope="col">Nombre</th>
                                <th scope="col">Ámbito</th>
                                <th scope="col">Folio</th>
                                <th scope="col" class="whitespace-normal">Semáforo Biometría</th>
                                <th scope="col" class="text-center">Estado</th>
                                <th scope="col" class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <template x-for="(elem, index) in elementosFiltrados" :key="elem.id">
                                <tr class="hover:bg-inst-50/20 transition-colors">
                                    <!-- 2. CUIP -->
                                    <td class="whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1 font-mono text-xs font-semibold text-inst-900">
                                            <span x-text="elem.cuip"></span>
                                            <button type="button"
                                                    @click="navigator.clipboard.writeText(elem.cuip)"
                                                    class="text-slate-400 hover:text-inst-700 transition"
                                                    title="Copiar CUIP" :aria-label="'Copiar CUIP ' + elem.cuip">
                                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                                </svg>
                                            </button>
                                        </div>
                                        <div class="font-mono text-2xs text-tx-soft" x-text="'#' + (index + 1) + (elem.numero_fila ? ' · Fila Excel ' + elem.numero_fila : '')"></div>
                                    </td>

                                    <!-- 3. Nombre -->
                                    <td>
                                        <div class="font-bold text-slate-900 leading-tight truncate max-w-[12rem]" :title="elem.nombre" x-text="elem.nombre"></div>
                                        <div class="text-2xs text-slate-500 mt-0.5 truncate max-w-[12rem]" x-text="[elem.categoria, elem.municipio].filter(Boolean).join(' · ')"></div>
                                    </td>

                                    <!-- 5. Ámbito -->
                                    <td class="whitespace-nowrap">
                                        <template x-if="elem.ambito_biometrico === 'ESTATALES'">
                                            <span class="inline-flex items-center rounded bg-sky-50 px-2 py-0.5 text-2xs font-semibold text-sky-800 border border-sky-200">
                                                Estatales
                                            </span>
                                        </template>
                                        <template x-if="elem.ambito_biometrico === 'PREVENCION'">
                                            <span class="inline-flex items-center rounded bg-purple-50 px-2 py-0.5 text-2xs font-semibold text-purple-800 border border-purple-200">
                                                Prevención
                                            </span>
                                        </template>
                                        <template x-if="elem.ambito_biometrico === 'NO_RESUELTO' || !elem.ambito_biometrico">
                                            <span class="inline-flex items-center rounded bg-slate-100 px-2 py-0.5 text-2xs font-semibold text-slate-600 border border-slate-200">
                                                No Resuelto
                                            </span>
                                        </template>
                                    </td>

                                    <!-- 6. Folio Institucional -->
                                    <td class="whitespace-nowrap">
                                        <span class="code-chip" x-text="elem.folio || '—'"></span>
                                    </td>

                                    <!-- 6. Semáforo biométrico por componente (criterio de la validación 4/4) -->
                                    <td>
                                        <div class="flex items-center gap-1" role="list" :aria-label="'Biometría de ' + (elem.nombre || elem.cuip)">
                                            @foreach ($componentesBio as $c)
                                                <span role="listitem"
                                                      class="relative flex h-7 w-7 items-center justify-center rounded-md border"
                                                      :class="claseComponente(elem, '{{ $c['key'] }}')"
                                                      :title="tituloComponente(elem, '{{ $c['key'] }}', '{{ $c['label'] }}')">
                                                    <x-icon :name="$c['icon']" class="h-3.5 w-3.5"/>
                                                    @if ($c['lado'])
                                                        <span class="absolute bottom-0 right-0.5 text-2xs font-bold leading-none" aria-hidden="true">{{ $c['lado'] }}</span>
                                                    @endif
                                                    <span class="sr-only" x-text="tituloComponente(elem, '{{ $c['key'] }}', '{{ $c['label'] }}')"></span>
                                                </span>
                                            @endforeach
                                        </div>
                                        <p class="mt-1 whitespace-nowrap text-2xs font-medium" :class="claseResumenBiometria(elem)" x-text="resumenBiometria(elem)"></p>
                                    </td>

                                    <!-- 8. Estado Trámite -->
                                    <td class="text-center whitespace-nowrap">
                                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-2xs font-semibold"
                                              :class="{
                                                  'bg-emerald-50 text-emerald-800 border border-emerald-200': elem.estado_tramite === 'listo_impresion',
                                                  'bg-amber-50 text-amber-800 border border-amber-200': elem.estado_tramite === 'pendiente_biometrico',
                                                  'bg-slate-100 text-slate-700 border border-slate-200': elem.estado_tramite !== 'listo_impresion' && elem.estado_tramite !== 'pendiente_biometrico'
                                              }">
                                            <span class="h-1.5 w-1.5 rounded-full"
                                                  :class="{
                                                      'bg-emerald-500': elem.estado_tramite === 'listo_impresion',
                                                      'bg-amber-500': elem.estado_tramite === 'pendiente_biometrico',
                                                      'bg-slate-400': elem.estado_tramite !== 'listo_impresion' && elem.estado_tramite !== 'pendiente_biometrico'
                                                  }"></span>
                                            <span x-text="etiquetaTramite(elem.estado_tramite)"></span>
                                        </span>
                                    </td>

                                    <!-- 8. Acciones: vista previa y edición son acciones distintas -->
                                    <td class="whitespace-nowrap text-center">
                                        <div class="inline-flex items-center gap-1">
                                            <button type="button"
                                                    @click="abrirModalPrevisualizacion(elem.id, null, 'vista')"
                                                    class="btn btn-secondary btn-sm btn-icon"
                                                    title="Vista previa de la credencial"
                                                    :aria-label="'Vista previa de la credencial de ' + (elem.nombre || elem.cuip)">
                                                <x-icon name="eye" class="h-4 w-4"/>
                                            </button>
                                            <button type="button"
                                                    @click="abrirModalPrevisualizacion(elem.id, null, 'edicion')"
                                                    class="btn btn-primary btn-sm btn-icon"
                                                    title="Datos y biometría"
                                                    :aria-label="'Datos y biometría de ' + (elem.nombre || elem.cuip)">
                                                <x-icon name="fingerprint" class="h-4 w-4"/>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>

                            <!-- Estado Vacío: Sin coincidencias tras filtros -->
                            <template x-if="elementos.length > 0 && elementosFiltrados.length === 0">
                                <tr>
                                    <td colspan="7" class="px-6 py-8 text-center text-xs text-tx-soft">
                                        No se encontraron elementos aptos que coincidan con los filtros aplicados.
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </x-card>

            <!-- Modal Interactivo de Dos Paneles: Vista Previa y Gestión Biométrica -->
            <div x-show="modalPrevisualizacion"
                 x-cloak
                 role="dialog" aria-modal="true" aria-labelledby="modal-previsualizacion-titulo"
                 @keydown.escape.window="cerrarModalPrevisualizacion()"
                 class="fixed inset-0 z-50 !mt-0 flex justify-center overflow-y-auto bg-inst-950/60 p-2 backdrop-blur-sm sm:p-3">
                <div @click.outside="cerrarModalPrevisualizacion()"
                     class="relative w-full max-w-6xl my-auto h-[94vh] max-h-[820px] min-h-[500px] flex flex-col rounded-xl bg-white shadow-2xl border border-slate-200 overflow-hidden text-left">
                    
                    <!-- Notificación Toast Flotante -->
                    <div x-show="toastMensaje"
                         x-cloak
                         x-transition:enter="transition ease-out duration-300 transform"
                         x-transition:enter-start="opacity-0 -translate-y-2 scale-95"
                         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                         x-transition:leave="transition ease-in duration-200 transform"
                         x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                         x-transition:leave-end="opacity-0 -translate-y-2 scale-95"
                         role="status" aria-live="polite" class="absolute top-4 right-4 z-50 max-w-sm rounded-lg bg-white p-3 shadow-pop border"
                         :class="toastTipo === 'exito' ? 'border-emerald-300 ring-1 ring-emerald-500/20' : 'border-rose-300 ring-1 ring-rose-500/20'">
                        <div class="flex items-start gap-2.5">
                            <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full"
                                 :class="toastTipo === 'exito' ? 'bg-emerald-100 text-emerald-600' : 'bg-rose-100 text-rose-600'">
                                <template x-if="toastTipo === 'exito'">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </template>
                                <template x-if="toastTipo !== 'exito'">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                    </svg>
                                </template>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h4 class="text-xs font-bold leading-tight"
                                    :class="toastTipo === 'exito' ? 'text-emerald-900' : 'text-rose-900'"
                                    x-text="toastTipo === 'exito' ? 'Operación Exitosa' : 'Incidencia Detectada'"></h4>
                                <p class="text-[11px] text-slate-600 mt-0.5 leading-snug" x-text="toastMensaje"></p>
                            </div>
                            <button type="button" @click="toastMensaje = ''" class="ml-1 rounded p-0.5 text-slate-500 hover:bg-slate-100 hover:text-slate-700" aria-label="Cerrar notificación"><x-icon name="x" class="h-3.5 w-3.5"/></button>
                        </div>
                    </div>

                    <!-- Encabezado del Modal -->
                    <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50/90 px-5 py-3.5 shrink-0">
                        <div class="flex items-center gap-3">
                            <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-inst-700 text-white shadow-xs">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                            </div>
                            <div>
                                <h3 id="modal-previsualizacion-titulo" class="text-sm font-semibold text-inst-900 sm:text-base"
                                    x-text="modoModal === 'vista' ? 'Vista previa de la credencial' : 'Datos y biometría de la credencial'">
                                    Vista previa de la credencial
                                </h3>
                                <template x-if="previewData">
                                    <p class="text-xs text-tx-soft">
                                        CUIP: <span class="font-mono font-bold text-inst-900" x-text="previewData.elemento.cuip"></span>
                                        · <span class="font-semibold text-slate-800" x-text="previewData.elemento.nombre"></span>
                                        · Tarjeta: <span class="font-mono" x-text="previewData.elemento.tarjeta"></span>
                                    </p>
                                </template>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <div class="hidden rounded-lg bg-slate-200/70 p-0.5 sm:inline-flex" role="group" aria-label="Modo de la ventana">
                                <button type="button" @click="cambiarModoModal('vista')" :aria-pressed="modoModal === 'vista' ? 'true' : 'false'"
                                        class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-semibold transition"
                                        :class="modoModal === 'vista' ? 'bg-white text-inst-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'">
                                    <x-icon name="eye" class="h-3.5 w-3.5"/>Vista previa
                                </button>
                                <button type="button" @click="cambiarModoModal('edicion')" :aria-pressed="modoModal === 'edicion' ? 'true' : 'false'"
                                        class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-semibold transition"
                                        :class="modoModal === 'edicion' ? 'bg-white text-inst-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'">
                                    <x-icon name="fingerprint" class="h-3.5 w-3.5"/>Datos y biometría
                                </button>
                            </div>
                            <template x-if="previewData">
                                <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold"
                                      :class="previewData.semaforo.es_completo ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-amber-50 text-amber-800 border border-amber-200'">
                                    <span class="h-1.5 w-1.5 rounded-full" :class="previewData.semaforo.es_completo ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                                    <span x-text="'Biometría ' + previewData.semaforo.completos + '/4'"></span>
                                </span>
                            </template>
                            <button type="button"
                                    @click="cerrarModalPrevisualizacion()"
                                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-200 hover:text-slate-700 transition"
                                    title="Cerrar ventana" aria-label="Cerrar vista previa">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Estado de Carga / Skeleton -->
                    <template x-if="cargandoPrevisualizacion">
                        <div class="flex flex-col items-center justify-center p-16 text-center flex-1 space-y-3">
                            <div class="relative flex h-14 w-14 items-center justify-center rounded-2xl bg-inst-50 text-inst-700 shadow-xs border border-inst-100">
                                <svg class="h-7 w-7 animate-spin text-inst-700" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                </svg>
                            </div>
                            <div class="text-center space-y-0.5">
                                <p class="text-sm font-bold text-slate-800">Cargando credencial y datos periciales...</p>
                                <p class="text-xs text-tx-soft">Recuperando registros biométricos, verificación SHA-256 y plantilla vertical CR-80.</p>
                            </div>
                        </div>
                    </template>

                    <!-- Contenido Dividido (Split) -->
                    <template x-if="!cargandoPrevisualizacion && previewData">
                        <div class="grid grid-cols-1 lg:grid-cols-12 divide-y lg:divide-y-0 lg:divide-x divide-slate-200 flex-1 min-h-0 overflow-hidden">
                            
                            <!-- ============================================== -->
                            <!-- PANEL IZQUIERDO: Mesa de Inspección Gráfica    -->
                            <!-- ============================================== -->
                            <div class="bg-slate-100/80 p-3 sm:p-4 flex flex-col justify-between overflow-y-auto h-full"
                                 :class="modoModal === 'vista' ? 'lg:col-span-12' : 'lg:col-span-6 xl:col-span-7'">
                                <!-- Conmutador de modo en pantallas pequeñas -->
                                <div class="mb-2 grid grid-cols-2 rounded-lg bg-slate-200/70 p-0.5 sm:hidden" role="group" aria-label="Modo de la ventana">
                                    <button type="button" @click="cambiarModoModal('vista')" :aria-pressed="modoModal === 'vista' ? 'true' : 'false'"
                                            class="rounded-md px-2 py-1 text-xs font-semibold" :class="modoModal === 'vista' ? 'bg-white text-inst-900 shadow-sm' : 'text-slate-600'">Vista previa</button>
                                    <button type="button" @click="cambiarModoModal('edicion')" :aria-pressed="modoModal === 'edicion' ? 'true' : 'false'"
                                            class="rounded-md px-2 py-1 text-xs font-semibold" :class="modoModal === 'edicion' ? 'bg-white text-inst-900 shadow-sm' : 'text-slate-600'">Datos y biometría</button>
                                </div>
                                <!-- Selector de Anverso / Reverso con selector de versión y especificación técnica -->
                                <div class="w-full flex flex-wrap items-center justify-between gap-2 mb-2 shrink-0">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex h-2 w-2 rounded-full bg-inst-700"></span>
                                        <span class="text-xs font-bold uppercase tracking-wider text-slate-700">CR-80 · 54 × 86 mm</span>
                                        
                                        <!-- Selector de Versión de Plantilla (v1 Base vs v2 Oficial Real) -->
                                        <div class="inline-flex rounded-lg bg-slate-200/80 p-0.5 shadow-inner text-[11px] ml-1">
                                            <button type="button"
                                                    @click="cambiarVersionPlantilla(1)"
                                                    :class="versionPlantillaSeleccionada === 1 ? 'bg-white text-slate-900 font-bold shadow-xs' : 'text-slate-600 hover:text-slate-900 font-medium'"
                                                    class="px-2 py-0.5 rounded-md transition-all"
                                                    title="Versión 1: Plantilla base institucional">
                                                v1 Base
                                            </button>
                                            <button type="button"
                                                    @click="cambiarVersionPlantilla(2)"
                                                    :class="versionPlantillaSeleccionada === 2 ? 'bg-inst-700 text-white font-bold shadow-xs' : 'text-slate-600 hover:text-slate-900 font-medium'"
                                                    class="px-2 py-0.5 rounded-md transition-all"
                                                    title="Versión 2: Reproducción oficial real física (Arial, CardStudio)">
                                                v2 Oficial Real
                                            </button>
                                        </div>
                                        <a :href="'{{ route('impresion.plantilla') }}?template_version=' + versionPlantillaSeleccionada"
                                           target="_blank"
                                           class="ml-1 inline-flex items-center gap-1 rounded px-2 py-0.5 text-[11px] font-semibold text-inst-700 hover:bg-inst-50 hover:text-inst-900 transition"
                                           title="Abrir editor para calibrar la versión seleccionada">
                                            <span>Editar</span>
                                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                            </svg>
                                        </a>
                                    </div>
                                    <div class="inline-flex rounded-lg bg-slate-200/80 p-0.5 shadow-inner">
                                        <button type="button"
                                                @click="alternarCara('front')"
                                                :class="caraCredencial === 'front' ? 'bg-inst-700 text-white font-bold shadow-xs' : 'text-slate-600 hover:text-slate-900 font-medium'"
                                                class="flex items-center gap-1.5 rounded-md px-3 py-1 text-xs transition-all duration-150">
                                            <span>Anverso</span>
                                        </button>
                                        <button type="button"
                                                @click="alternarCara('rear')"
                                                :class="caraCredencial === 'rear' ? 'bg-inst-700 text-white font-bold shadow-xs' : 'text-slate-600 hover:text-slate-900 font-medium'"
                                                class="flex items-center gap-1.5 rounded-md px-3 py-1 text-xs transition-all duration-150">
                                            <span>Reverso</span>
                                        </button>
                                    </div>
                                </div>

                                <!-- Mesa de Montaje Físico de Tarjeta PVC -->
                                <div class="relative group my-auto flex items-center justify-center py-1 sm:py-2">
                                    <!-- Resplandor Perimetral de Elevación -->
                                    <div class="absolute -inset-1.5 rounded-[18px] bg-gradient-to-b from-inst-700/10 via-slate-400/15 to-slate-800/20 blur-xs pointer-events-none"></div>

                                    <!-- Contenedor Físico Escalado de la Credencial CR-80 (310 x 494 px / responsive) -->
                                    <div class="card-preview-viewport relative rounded-[14px] shadow-2xl border border-slate-300/80 ring-1 ring-black/5 overflow-hidden bg-white select-none transition-all duration-300 transform"
                                         :class="animandoGiro ? 'scale-95 opacity-75' : 'scale-100 opacity-100'">
                                        
                                        <!-- Brillo Especular de Acabado PVC Laminado -->
                                        <div class="pointer-events-none absolute inset-0 z-30 rounded-[14px] bg-gradient-to-tr from-transparent via-white/5 to-white/20 opacity-70"></div>

                                        <!-- Lienzo físico idéntico a la vista térmica (mm/pt), escalado al visor para paridad WYSIWYG -->
                                        <div data-card-canvas
                                             class="absolute left-0 top-0 origin-top-left"
                                             :style="`width: ${previewData.template.width_mm}mm; height: ${previewData.template.height_mm}mm; transform: scale(${escalaTarjeta});`"
                                             x-effect="caraCredencial; previewData; $nextTick(() => ajustarTarjeta())"
                                             @resize.window.debounce.150ms="ajustarTarjeta()">
                                        <!-- Fondo Institucional Reactivo -->
                                        <img :src="caraCredencial === 'front' ? previewData.template.front_bg : previewData.template.rear_bg"
                                             class="absolute inset-0 w-full h-full object-fill pointer-events-none transition-opacity duration-200"
                                             alt="Fondo institucional de credencial">

                                        <!-- Elementos de la Cara Activa -->
                                        <template x-for="el in (caraCredencial === 'front' ? previewData.template.config.faces.front.elements : previewData.template.config.faces.rear.elements)"
                                                  :key="caraCredencial + '_' + el.id">
                                            <div x-show="el.visible"
                                                 class="credential-print-element"
                                                 :data-font-size="el.font_size"
                                                 :data-rotation="el.rotation || 0"
                                                 :data-width="el.width"
                                                 :data-height="el.height"
                                                 :style="styleCardElement(el)">
                                                
                                                <!-- Caso 1 y 2: Texto Fijo o Campo Dinámico -->
                                                <template x-if="el.kind === 'static' || el.kind === 'dynamic'">
                                                    <span :style="styleSpanText(el)"
                                                          :title="getTextContent(el)"
                                                          x-text="getTextContent(el)"></span>
                                                </template>

                                                <!-- Caso 3: Código de Barras Vectorial -->
                                                <template x-if="el.kind === 'barcode'">
                                                    <div class="w-full h-full flex flex-col items-center justify-center bg-white/90 p-0.5 rounded-xs border border-slate-300 select-none">
                                                        <svg class="w-full h-3.5 text-slate-900" viewBox="0 0 100 20" preserveAspectRatio="none">
                                                            <rect x="0" y="0" width="3" height="20" fill="currentColor"/>
                                                            <rect x="5" y="0" width="2" height="20" fill="currentColor"/>
                                                            <rect x="9" y="0" width="4" height="20" fill="currentColor"/>
                                                            <rect x="15" y="0" width="1" height="20" fill="currentColor"/>
                                                            <rect x="18" y="0" width="3" height="20" fill="currentColor"/>
                                                            <rect x="23" y="0" width="2" height="20" fill="currentColor"/>
                                                            <rect x="27" y="0" width="5" height="20" fill="currentColor"/>
                                                            <rect x="34" y="0" width="2" height="20" fill="currentColor"/>
                                                            <rect x="38" y="0" width="3" height="20" fill="currentColor"/>
                                                            <rect x="43" y="0" width="1" height="20" fill="currentColor"/>
                                                            <rect x="46" y="0" width="4" height="20" fill="currentColor"/>
                                                            <rect x="52" y="0" width="2" height="20" fill="currentColor"/>
                                                            <rect x="56" y="0" width="3" height="20" fill="currentColor"/>
                                                            <rect x="61" y="0" width="2" height="20" fill="currentColor"/>
                                                            <rect x="65" y="0" width="4" height="20" fill="currentColor"/>
                                                            <rect x="71" y="0" width="1" height="20" fill="currentColor"/>
                                                            <rect x="74" y="0" width="3" height="20" fill="currentColor"/>
                                                            <rect x="79" y="0" width="2" height="20" fill="currentColor"/>
                                                            <rect x="83" y="0" width="4" height="20" fill="currentColor"/>
                                                            <rect x="89" y="0" width="2" height="20" fill="currentColor"/>
                                                            <rect x="93" y="0" width="3" height="20" fill="currentColor"/>
                                                            <rect x="98" y="0" width="2" height="20" fill="currentColor"/>
                                                        </svg>
                                                        <span class="font-mono text-[6.5px] font-bold text-slate-900 tracking-wider" x-text="previewData.elemento.cuip"></span>
                                                    </div>
                                                </template>

                                                <!-- Caso 4: Recurso Institucional Fijo (asset_path) -->
                                                <template x-if="el.kind === 'image' && el.asset_path && previewData.template.recursos[el.id]">
                                                    <img :src="previewData.template.recursos[el.id]"
                                                         class="w-full h-full object-contain pointer-events-none"
                                                         :alt="el.label || el.id">
                                                </template>

                                                <!-- Caso 5A: Microfotografía Institucional de Seguridad (foto_secundaria) -->
                                                <template x-if="el.kind === 'image' && !el.asset_path && el.id === 'foto_secundaria'">
                                                    <div class="w-full h-full flex items-center justify-center overflow-hidden rounded-xs bg-slate-100/90 border border-slate-300 shadow-2xs"
                                                         title="Microfotografía institucional de seguridad">
                                                        <template x-if="previewData.biometricos.foto.existe">
                                                            <img :src="previewData.biometricos.foto.url + '?t=' + cacheBuster"
                                                                 class="w-full h-full object-cover opacity-90 contrast-105"
                                                                 alt="Microfotografía institucional de seguridad">
                                                        </template>
                                                        <template x-if="!previewData.biometricos.foto.existe">
                                                            <div class="flex flex-col items-center justify-center p-0.5 text-slate-400 select-none">
                                                                <svg class="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                                                </svg>
                                                                <span class="text-[5px] uppercase font-bold text-slate-500 leading-tight">Seguridad</span>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>

                                                <!-- Caso 5B: Fotografía Facial Principal (foto_principal) -->
                                                <template x-if="el.kind === 'image' && !el.asset_path && el.field === 'fotografia' && el.id !== 'foto_secundaria'">
                                                    <div class="w-full h-full flex items-center justify-center overflow-hidden rounded-xs bg-slate-100"
                                                         :style="el.border ? { border: el.border } : { border: '1px solid #cbd5e1' }">
                                                        <template x-if="previewData.biometricos.foto.existe">
                                                            <img :src="previewData.biometricos.foto.url + '?t=' + cacheBuster"
                                                                 class="w-full h-full object-cover"
                                                                 alt="Fotografía facial del elemento">
                                                        </template>
                                                        <template x-if="!previewData.biometricos.foto.existe">
                                                            <div class="flex flex-col items-center justify-center p-1 text-slate-400 select-none">
                                                                <svg class="h-8 w-8 text-slate-300" fill="currentColor" viewBox="0 0 24 24">
                                                                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                                                                </svg>
                                                                <span class="text-[7px] uppercase font-bold text-amber-700 mt-0.5">Sin Foto</span>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>

                                                <!-- Caso 6: Firma del Titular -->
                                                <template x-if="el.kind === 'image' && !el.asset_path && (el.field === 'firma_titular' || el.field === 'firma_persona')">
                                                    <div class="w-full h-full flex items-center justify-center overflow-hidden">
                                                        <template x-if="previewData.biometricos.firma.existe">
                                                            <img :src="previewData.biometricos.firma.url + '?t=' + cacheBuster"
                                                                 class="w-full h-full object-contain"
                                                                 alt="Firma del elemento">
                                                        </template>
                                                        <template x-if="!previewData.biometricos.firma.existe">
                                                            <div class="w-full border-b border-dashed border-slate-400 text-center pb-0.5 select-none">
                                                                <span class="text-[6.5px] italic text-amber-700">Firma pendiente</span>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>

                                                <!-- Caso 7: Huella Pulgar Derecho -->
                                                <template x-if="el.kind === 'image' && !el.asset_path && el.field === 'huella_derecha'">
                                                    <div class="w-full h-full flex items-center justify-center overflow-hidden rounded-xs"
                                                         :style="el.border ? { border: el.border } : (previewData.biometricos.pulgar_derecho.existe ? { border: '1px solid #cbd5e1' } : {})"
                                                         :class="el.border ? 'bg-white' : (previewData.biometricos.pulgar_derecho.existe ? 'bg-white' : 'border border-dashed border-slate-300 bg-slate-50/50')">
                                                        <template x-if="previewData.biometricos.pulgar_derecho.existe">
                                                            <img :src="previewData.biometricos.pulgar_derecho.url + '?t=' + cacheBuster"
                                                                 class="w-full h-full object-contain"
                                                                 alt="Huella dactilar pulgar derecho">
                                                        </template>
                                                        <template x-if="!previewData.biometricos.pulgar_derecho.existe">
                                                            <div class="flex flex-col items-center justify-center p-0.5 text-slate-300 select-none">
                                                                <svg class="h-6 w-6 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 004.07 9m5.93 11a21.84 21.84 0 004.5-2.5" />
                                                                </svg>
                                                                <span class="text-[6px] text-amber-700 font-semibold">P. Der Pendiente</span>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>

                                                <!-- Caso 8: Huella Pulgar Izquierdo -->
                                                <template x-if="el.kind === 'image' && !el.asset_path && el.field === 'huella_izquierda'">
                                                    <div class="w-full h-full flex items-center justify-center overflow-hidden rounded-xs"
                                                         :style="el.border ? { border: el.border } : (previewData.biometricos.pulgar_izquierdo.existe ? { border: '1px solid #cbd5e1' } : {})"
                                                         :class="el.border ? 'bg-white' : (previewData.biometricos.pulgar_izquierdo.existe ? 'bg-white' : 'border border-dashed border-slate-300 bg-slate-50/50')">
                                                        <template x-if="previewData.biometricos.pulgar_izquierdo.existe">
                                                            <img :src="previewData.biometricos.pulgar_izquierdo.url + '?t=' + cacheBuster"
                                                                 class="w-full h-full object-contain"
                                                                 alt="Huella dactilar pulgar izquierdo">
                                                        </template>
                                                        <template x-if="!previewData.biometricos.pulgar_izquierdo.existe">
                                                            <div class="flex flex-col items-center justify-center p-0.5 text-slate-300 select-none">
                                                                <svg class="h-6 w-6 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 004.07 9m5.93 11a21.84 21.84 0 004.5-2.5" />
                                                                </svg>
                                                                <span class="text-[6px] text-amber-700 font-semibold">P. Izq Pendiente</span>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                        </div>
                                    </div>
                                </div>

                                <!-- Botón de Salida Física a Impresión Térmica -->
                                <div class="w-full pt-3 flex items-center justify-between border-t border-slate-200 mt-2 shrink-0">
                                    <div>
                                        <template x-if="previewData.elemento.puede_imprimir">
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-semibold bg-emerald-50 text-emerald-800 border border-emerald-200">
                                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                                Apto para Impresión Oficial
                                            </span>
                                        </template>
                                        <template x-if="!previewData.elemento.puede_imprimir">
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-semibold bg-amber-50 text-amber-800 border border-amber-200"
                                                  title="Requiere validación biométrica 4/4 y asignación de folio">
                                                <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                                                Expediente Biométrico Incompleto
                                            </span>
                                        </template>
                                    </div>

                                    <div>
                                        <template x-if="previewData.elemento.puede_imprimir">
                                            <a :href="'{{ route('impresion.plantilla.imprimir') }}?elemento_id=' + previewData.elemento.id + '&template_version=' + versionPlantillaSeleccionada"
                                               target="_blank"
                                               class="inline-flex items-center gap-1.5 rounded-lg bg-inst-700 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-inst-900 transition">
                                                <svg class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                                </svg>
                                                <span>Abrir Formato Térmico</span>
                                            </a>
                                        </template>
                                        <template x-if="!previewData.elemento.puede_imprimir">
                                            <button type="button" disabled class="inline-flex items-center gap-1.5 rounded-lg bg-slate-200 px-4 py-2 text-xs font-semibold text-slate-500 cursor-not-allowed"
                                                    title="Impresión restringida hasta contar con los 4 biométricos oficiales y folio asignado">
                                                <span>Impresión Bloqueada</span>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </div>

                            <!-- ============================================== -->
                            <!-- PANEL DERECHO: Consola de Periféricos y Biometría -->
                            <!-- ============================================== -->
                            <div x-show="modoModal === 'edicion'" class="lg:col-span-6 xl:col-span-5 bg-white p-3 sm:p-4 overflow-y-auto flex flex-col justify-between h-full space-y-3">
                                <div class="flex border-b border-linea" role="tablist" aria-label="Secciones de edición">
                                    <button type="button" role="tab" @click="pestanaEdicion = 'biometria'" :aria-selected="pestanaEdicion === 'biometria' ? 'true' : 'false'"
                                            class="-mb-px flex items-center gap-1.5 border-b-2 px-3 pb-2 text-sm font-medium transition-colors"
                                            :class="pestanaEdicion === 'biometria' ? 'border-inst-700 text-inst-900' : 'border-transparent text-tx-soft hover:text-tx'">
                                        <x-icon name="fingerprint" class="h-4 w-4"/>Estación biométrica
                                        <span class="rounded-full px-1.5 text-2xs font-semibold" :class="componentesPendientes() ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700'" x-text="componentesPendientes() ? componentesPendientes() + ' por atender' : '4/4'"></span>
                                    </button>
                                    <button type="button" role="tab" @click="pestanaEdicion = 'datos'" :aria-selected="pestanaEdicion === 'datos' ? 'true' : 'false'"
                                            class="-mb-px flex items-center gap-1.5 border-b-2 px-3 pb-2 text-sm font-medium transition-colors"
                                            :class="pestanaEdicion === 'datos' ? 'border-inst-700 text-inst-900' : 'border-transparent text-tx-soft hover:text-tx'">
                                        <x-icon name="idcard" class="h-4 w-4"/>Datos de la credencial
                                    </button>
                                </div>

                                <!-- Datos que alimentan la credencial: solo lectura (no existe edición desde Impresión) -->
                                <section x-show="pestanaEdicion === 'datos'" class="space-y-4" aria-label="Datos de la credencial">
                                    <div class="alert alert-info text-xs">
                                        <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0"/>
                                        <span>Estos datos provienen del padrón LOC, el inventario de armamento, las vigencias y la solicitud. No se editan desde Impresión: si alguno es incorrecto, corríjalo en su fuente y vuelva a abrir la vista previa.</span>
                                    </div>
                                    @foreach ([
                                        'Anverso' => ['folio' => 'Folio', 'numero_tarjeta' => 'Tarjeta de trabajo', 'cuip' => 'CUIP', 'nombre' => 'Nombre', 'categoria' => 'Categoría', 'fecha_elaboracion' => 'Fecha de elaboración', 'vigencia' => 'Vigencia'],
                                        'Reverso' => ['subdireccion' => 'Subdirección', 'ubicacion' => 'Ubicación', 'tipo_arma' => 'Tipo de arma', 'clase_arma' => 'Clase', 'marca' => 'Marca', 'modelo' => 'Modelo', 'calibre' => 'Calibre', 'matricula' => 'Matrícula', 'rfa' => 'RFA', 'resguardo' => 'Resguardo'],
                                    ] as $cara => $campos)
                                        <div>
                                            <h4 class="eyebrow mb-2">{{ $cara }}</h4>
                                            <dl class="grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-2">
                                                @foreach ($campos as $clave => $etiqueta)
                                                    <div class="min-w-0 rounded-md bg-slate-50 px-2.5 py-1.5 ring-1 ring-slate-200">
                                                        <dt class="text-2xs font-medium text-tx-soft">{{ $etiqueta }}</dt>
                                                        <dd class="whitespace-pre-line break-words text-xs font-semibold text-tx" x-text="previewData.campos_valores.{{ $clave }} || '—'"></dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        </div>
                                    @endforeach
                                </section>

                                <div x-show="pestanaEdicion === 'biometria'" class="space-y-3.5">
                                    <!-- Periféricos: solo estados que la estación puede comprobar; nada se da por conectado -->
                                    <section class="rounded-lg border border-linea bg-slate-50/70 p-3" aria-labelledby="perifericos-titulo">
                                        <div class="mb-2 flex items-center justify-between gap-2">
                                            <h4 id="perifericos-titulo" class="eyebrow flex items-center gap-1.5"><x-icon name="device" class="h-3.5 w-3.5"/>Periféricos de la estación</h4>
                                            <span class="font-mono text-2xs text-tx-soft" x-text="previewData.elemento.folio ? 'Folio ' + previewData.elemento.folio : 'Sin folio asignado'"></span>
                                        </div>
                                        <ul class="divide-y divide-slate-200 overflow-hidden rounded-md bg-white ring-1 ring-slate-200">
                                            <li class="flex items-center gap-2.5 px-2.5 py-2" title="Cámara del navegador (getUserMedia)">
                                                <x-icon name="camera" class="h-4 w-4 shrink-0 text-tx-soft"/>
                                                <span class="flex min-w-0 flex-1 items-center justify-between gap-2">
                                                    <span class="text-xs font-medium text-tx">Cámara</span>
                                                    <span class="flex items-center gap-1 text-2xs" :class="camaraActiva ? 'text-emerald-700' : 'text-tx-soft'">
                                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="camaraActiva ? 'bg-emerald-500' : (camaraIniciando ? 'bg-inst-500 animate-pulse' : 'bg-slate-400')" aria-hidden="true"></span>
                                                        <span x-text="camaraActiva ? 'Activa' : (camaraIniciando ? 'Iniciando…' : 'Inactiva')"></span>
                                                    </span>
                                                </span>
                                            </li>
                                            <li class="flex items-center gap-2.5 px-2.5 py-2" title="Cámara Canon EOS mediante el agente local de la estación (127.0.0.1)">
                                                <x-icon name="camera" class="h-4 w-4 shrink-0 text-tx-soft"/>
                                                <span class="flex min-w-0 flex-1 items-center justify-between gap-2">
                                                    <span class="text-xs font-medium text-tx">Cámara Canon (agente)</span>
                                                    <span class="flex items-center gap-1 text-2xs" :class="estadoCanon().texto">
                                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" aria-hidden="true" :class="estadoCanon().punto"></span>
                                                        <span x-text="estadoCanon().etiqueta"></span>
                                                    </span>
                                                </span>
                                            </li>
                                            <li class="flex items-center gap-2.5 px-2.5 py-2" title="Pad de firma Topaz mediante el servicio local SigWeb">
                                                <x-icon name="pen" class="h-4 w-4 shrink-0 text-tx-soft"/>
                                                <span class="flex min-w-0 flex-1 items-center justify-between gap-2">
                                                    <span class="text-xs font-medium text-tx">Pad de firma</span>
                                                    <span class="flex items-center gap-1 text-2xs" :class="estadoPadTopaz().texto">
                                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" aria-hidden="true" :class="estadoPadTopaz().punto"></span>
                                                        <span x-text="estadoPadTopaz().etiqueta"></span>
                                                    </span>
                                                </span>
                                            </li>
                                            <li class="flex items-center gap-2.5 px-2.5 py-2" title="Lector Cross Match Verifier 300 LC 2.0 mediante el agente local de la estación (127.0.0.1)">
                                                <x-icon name="fingerprint" class="h-4 w-4 shrink-0 text-tx-soft"/>
                                                <span class="flex min-w-0 flex-1 items-center justify-between gap-2">
                                                    <span class="text-xs font-medium text-tx">Lector Cross Match (agente)</span>
                                                    <span class="flex items-center gap-1 text-2xs" :class="estadoHuella().texto">
                                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" aria-hidden="true" :class="estadoHuella().punto"></span>
                                                        <span x-text="estadoHuella().etiqueta"></span>
                                                    </span>
                                                </span>
                                            </li>
                                        </ul>
                                    </section>

                                    <!-- Matriz 2x2 de Micro-Tarjetas de Biometría (Slots de Expediente) -->
                                    <div>
                                        <div class="flex items-center justify-between mb-1.5">
                                            <span class="text-xs font-bold uppercase tracking-wider text-slate-700">Expediente biométrico</span>
                                            <span class="text-2xs text-tx-soft">Seleccione un componente para consultarlo o actualizarlo</span>
                                        </div>
                                        <div class="grid grid-cols-2 gap-2">
                                            @foreach ([
                                                ['key' => 'foto', 'label' => 'Fotografía', 'icon' => 'camera'],
                                                ['key' => 'firma', 'label' => 'Firma', 'icon' => 'pen'],
                                                ['key' => 'pulgar_izquierdo', 'label' => 'Pulgar izquierdo', 'icon' => 'fingerprint'],
                                                ['key' => 'pulgar_derecho', 'label' => 'Pulgar derecho', 'icon' => 'fingerprint'],
                                            ] as $slot)
                                                @php $bio = "previewData.biometricos.{$slot['key']}"; @endphp
                                                <div class="relative">
                                                    <button type="button"
                                                            @click="cambiarSlot('{{ $slot['key'] }}')"
                                                            :aria-pressed="slotActivo === '{{ $slot['key'] }}' ? 'true' : 'false'"
                                                            :title="tituloEstadoSlot('{{ $slot['key'] }}')"
                                                            class="flex w-full items-center gap-2.5 rounded-lg p-2 pr-6 text-left transition-colors"
                                                            :class="slotActivo === '{{ $slot['key'] }}' ? 'bg-inst-50 ring-2 ring-inst-600' : 'bg-white ring-1 ring-slate-200 hover:bg-slate-50 hover:ring-slate-300'">
                                                        <span class="relative flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-md bg-slate-100 ring-1 ring-slate-200"
                                                              :class="!urlPendiente('{{ $slot['key'] }}') && {{ $bio }}.url && miniaturas.{{ $slot['key'] }} === 'cargando' ? 'animate-pulse' : ''">
                                                            <template x-if="urlPendiente('{{ $slot['key'] }}')">
                                                                <img :src="urlPendiente('{{ $slot['key'] }}')" alt="Captura pendiente de guardar" class="h-full w-full bg-white object-contain ring-2 ring-inset ring-inst-500">
                                                            </template>
                                                            <template x-if="!urlPendiente('{{ $slot['key'] }}') && {{ $bio }}.url && miniaturas.{{ $slot['key'] }} !== 'error'">
                                                                <img :src="{{ $bio }}.url" alt="" class="h-full w-full bg-white object-contain"
                                                                     x-on:load="miniaturas.{{ $slot['key'] }} = 'ok'" x-on:error="miniaturas.{{ $slot['key'] }} = 'error'">
                                                            </template>
                                                            <template x-if="!urlPendiente('{{ $slot['key'] }}') && (!{{ $bio }}.url || miniaturas.{{ $slot['key'] }} === 'error')">
                                                                <x-icon :name="$slot['icon']" class="h-5 w-5 text-slate-400"/>
                                                            </template>
                                                        </span>
                                                        <span class="min-w-0 flex-1">
                                                            <span class="block text-xs font-semibold text-tx">{{ $slot['label'] }}</span>
                                                            <span class="mt-1 inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-2xs font-semibold ring-1 ring-inset"
                                                                  :class="claseEstadoSlot('{{ $slot['key'] }}')">
                                                                <span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>
                                                                <span x-text="etiquetaEstadoSlot('{{ $slot['key'] }}')"></span>
                                                            </span>
                                                            <span class="mt-1 block truncate font-mono text-2xs text-tx-soft"
                                                                  x-text="{{ $bio }}.existe ? ('v' + ({{ $bio }}.version || 1) + ' · ' + ({{ $bio }}.sha256 ? {{ $bio }}.sha256.substring(0, 8) + '…' : 'sin hash')) : 'Sin archivo registrado'"></span>
                                                        </span>
                                                    </button>
                                                    <template x-if="{{ $bio }}.sha256">
                                                        <button type="button" @click="copiarHash({{ $bio }}.sha256)"
                                                                class="absolute bottom-2 right-2 rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-inst-700"
                                                                title="Copiar SHA-256" aria-label="Copiar SHA-256 de {{ mb_strtolower($slot['label']) }}">
                                                            <x-icon name="copy" class="h-3 w-3"/>
                                                        </button>
                                                    </template>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>

                                    <!-- ============================================== -->
                                    <!-- SECCIÓN ACTIVA: FOTOGRAFÍA FACIAL             -->
                                    <!-- ============================================== -->
                                    <div x-show="slotActivo === 'foto'" class="space-y-3">
                                        <!-- Sub-pestañas de Fotografía -->
                                        <div class="flex items-center justify-between border-b border-slate-200 pb-1.5">
                                            <span class="text-xs font-semibold text-slate-800">1. Fotografía Facial</span>
                                            <div class="inline-flex rounded-md bg-slate-100 p-0.5 text-2xs">
                                                <button type="button"
                                                        @click="modoCapturaFoto = 'camara'"
                                                        :class="modoCapturaFoto === 'camara' ? 'bg-white text-inst-900 font-bold shadow-2xs' : 'text-slate-600 hover:text-slate-900'"
                                                        class="rounded px-2.5 py-0.5 transition">Cámara Web / USB</button>
                                                <button type="button"
                                                        @click="modoCapturaFoto = 'canon'"
                                                        :class="modoCapturaFoto === 'canon' ? 'bg-white text-inst-900 font-bold shadow-2xs' : 'text-slate-600 hover:text-slate-900'"
                                                        class="rounded px-2.5 py-0.5 transition">Canon EOS (agente)</button>
                                                <button type="button"
                                                        @click="modoCapturaFoto = 'archivo'"
                                                        :class="modoCapturaFoto === 'archivo' ? 'bg-white text-inst-900 font-bold shadow-2xs' : 'text-slate-600 hover:text-slate-900'"
                                                        class="rounded px-2.5 py-0.5 transition">Archivo / EOS Utility</button>
                                            </div>
                                        </div>

                                        <!-- Canal A: Cámara en Vivo con Retícula Pericial -->
                                        <div x-show="modoCapturaFoto === 'camara'" class="space-y-2">
                                            <!-- Visor de Video / Captura -->
                                            <div class="relative w-full max-w-[280px] mx-auto aspect-[3/4] bg-slate-900 rounded-lg overflow-hidden border border-slate-300 shadow-md flex items-center justify-center text-white">
                                                
                                                <!-- Estado 1: Cámara Inactiva y sin foto capturada -->
                                                <template x-if="!camaraActiva && !fotoCapturadaUrl">
                                                    <div class="flex flex-col items-center justify-center p-4 text-center">
                                                        <div class="h-12 w-12 rounded-full bg-slate-800 flex items-center justify-center text-slate-300 mb-2">
                                                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                            </svg>
                                                        </div>
                                                        <p class="text-xs font-semibold text-slate-200">Cámara inactiva</p>
                                                        <p class="text-2xs text-slate-400 mt-0.5">Active la cámara para captura pericial en vivo.</p>
                                                        <button type="button"
                                                                @click="iniciarCamara()"
                                                                :disabled="camaraIniciando"
                                                                class="mt-3 rounded-md bg-inst-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-inst-800 transition">
                                                            <span x-text="camaraIniciando ? 'Iniciando...' : 'Activar Cámara en Vivo'"></span>
                                                        </button>
                                                    </div>
                                                </template>

                                                <!-- Estado 2: Cámara Activa en Vivo -->
                                                <div x-show="camaraActiva && !fotoCapturadaUrl" class="relative w-full h-full">
                                                    <video id="videoCapturaFacial" autoplay playsinline muted class="w-full h-full object-cover"></video>
                                                    
                                                    <!-- Retícula Pericial Facial ICAO / FIPS -->
                                                    <div class="pointer-events-none absolute inset-0 flex items-center justify-center">
                                                        <svg class="w-full h-full text-emerald-400/80 drop-shadow-sm" viewBox="0 0 200 260" fill="none">
                                                            <ellipse cx="100" cy="115" rx="55" ry="75" stroke="currentColor" stroke-width="1.8" stroke-dasharray="4 2"/>
                                                            <line x1="60" y1="95" x2="140" y2="95" stroke="currentColor" stroke-width="1" stroke-dasharray="2 2"/>
                                                            <text x="100" y="88" text-anchor="middle" fill="currentColor" font-size="7" font-weight="bold" font-family="sans-serif">NIVEL DE OJOS</text>
                                                            <line x1="75" y1="190" x2="125" y2="190" stroke="currentColor" stroke-width="1.5"/>
                                                            <text x="100" y="202" text-anchor="middle" fill="currentColor" font-size="7" font-weight="bold" font-family="sans-serif">LÍMITE BARBILLA</text>
                                                            <line x1="100" y1="110" x2="100" y2="120" stroke="currentColor" stroke-width="1"/>
                                                            <line x1="95" y1="115" x2="105" y2="115" stroke="currentColor" stroke-width="1"/>
                                                        </svg>
                                                    </div>

                                                    <div class="absolute bottom-2 inset-x-2 flex items-center justify-between gap-1">
                                                        <button type="button" @click="detenerCamara()" class="rounded bg-black/60 px-2 py-1 text-2xs text-white hover:bg-black/80 transition">Detener</button>
                                                        <button type="button" @click="capturarFoto()" class="rounded-md bg-rose-600 px-3 py-1 text-xs font-bold text-white shadow-sm hover:bg-rose-700 transition flex items-center gap-1">
                                                            <span class="h-2 w-2 rounded-full bg-white animate-ping"></span>
                                                            Capturar
                                                        </button>
                                                    </div>
                                                </div>

                                                <!-- Estado 3: Fotograma Capturado -->
                                                <div x-show="fotoCapturadaUrl" class="relative w-full h-full bg-black">
                                                    <img :src="fotoCapturadaUrl" class="w-full h-full object-cover" alt="Fotografía capturada">
                                                    <div class="absolute bottom-2 inset-x-2 flex items-center justify-between gap-1 bg-black/60 p-1.5 rounded">
                                                        <span class="text-2xs text-emerald-400 font-semibold">✓ Fotograma 3:4</span>
                                                        <button type="button" @click="descartarFotoCapturada()" class="rounded bg-slate-700 px-2 py-0.5 text-2xs text-white hover:bg-slate-600 transition">Reintentar</button>
                                                    </div>
                                                </div>
                                            </div>

                                            <div x-show="camaraError" class="text-[11px] text-rose-600 font-medium text-center" x-text="camaraError"></div>
                                        </div>

                                        <!-- Canal C: Cámara Canon EOS mediante el agente local de la estación -->
                                        <div x-show="modoCapturaFoto === 'canon'" class="rounded-lg border border-slate-200 bg-slate-50 p-3.5 space-y-2.5">
                                            <div class="flex items-center justify-between">
                                                <span class="text-xs font-bold text-slate-800">Canon EOS Rebel T6 · agente de estación</span>
                                                <span class="flex items-center gap-1 rounded px-2 py-0.5 text-2xs font-bold" :class="estadoCanon().insignia" role="status">
                                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full" aria-hidden="true" :class="estadoCanon().punto"></span>
                                                    <span x-text="estadoCanon().etiqueta"></span>
                                                </span>
                                            </div>

                                            <div x-show="canonSimulacion" class="rounded border border-amber-300 bg-amber-50 p-2 text-2xs font-semibold text-amber-900">
                                                Modo simulación del agente: las imágenes no provienen de una cámara y no pueden guardarse en el expediente.
                                            </div>

                                            <div class="relative mx-auto aspect-[3/4] w-full max-w-[280px] overflow-hidden rounded-lg border border-slate-300 bg-slate-900">
                                                <template x-if="canonCapturaUrl">
                                                    <img :src="canonCapturaUrl" class="h-full w-full object-cover" alt="Fotografía capturada con la cámara Canon">
                                                </template>
                                                <template x-if="!canonCapturaUrl && canonVistaUrl">
                                                    <img :src="canonVistaUrl" class="h-full w-full object-cover" alt="Vista previa de la cámara Canon">
                                                </template>
                                                <template x-if="!canonCapturaUrl && !canonVistaUrl">
                                                    <div class="flex h-full items-center justify-center p-4 text-center text-2xs text-slate-300" x-text="canonEstado === 'vista_previa' ? 'Esperando fotograma…' : 'Sin vista previa'"></div>
                                                </template>
                                                <div x-show="canonEstado === 'vista_previa' && canonVistaUrl" class="pointer-events-none absolute inset-0">
                                                    <svg class="h-full w-full text-emerald-400/80" viewBox="0 0 200 260" fill="none" aria-hidden="true">
                                                        <ellipse cx="100" cy="115" rx="55" ry="75" stroke="currentColor" stroke-width="1.8" stroke-dasharray="4 2"/>
                                                        <line x1="60" y1="95" x2="140" y2="95" stroke="currentColor" stroke-width="1" stroke-dasharray="2 2"/>
                                                        <line x1="75" y1="190" x2="125" y2="190" stroke="currentColor" stroke-width="1.5"/>
                                                    </svg>
                                                </div>
                                            </div>

                                            <div x-show="canonMensaje" class="rounded border bg-white p-2 text-2xs" :class="canonEstado === 'error' ? 'border-red-300 text-red-800' : 'border-slate-200 text-slate-700'" x-text="canonMensaje" aria-live="polite"></div>

                                            <div class="flex flex-wrap items-center gap-2">
                                                <button type="button" @click="verificarCanon()" :disabled="canonProcesando || ['vista_previa', 'capturada'].includes(canonEstado)"
                                                        class="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 transition disabled:cursor-not-allowed disabled:opacity-50">
                                                    Verificar cámara
                                                </button>
                                                <button type="button" x-show="!['vista_previa', 'capturada'].includes(canonEstado)" @click="iniciarCanon()" :disabled="canonProcesando || !elementoSeleccionadoId"
                                                        class="rounded bg-inst-700 px-3 py-1 text-xs font-semibold text-white shadow-2xs hover:bg-inst-800 transition disabled:cursor-not-allowed disabled:opacity-50">
                                                    Iniciar vista previa
                                                </button>
                                                <button type="button" x-show="canonEstado === 'vista_previa'" @click="capturarCanon()" :disabled="canonProcesando"
                                                        class="rounded bg-rose-600 px-3 py-1 text-xs font-semibold text-white shadow-2xs hover:bg-rose-700 transition disabled:opacity-50">
                                                    Capturar
                                                </button>
                                                <button type="button" x-show="canonEstado === 'capturada'" @click="repetirCanon()" :disabled="canonProcesando"
                                                        class="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 transition disabled:opacity-50">
                                                    Repetir
                                                </button>
                                                <button type="button" x-show="canonEstado === 'capturada' && !canonSimulacion" @click="usarFotoCanon()" :disabled="canonProcesando"
                                                        class="rounded bg-emerald-700 px-3 py-1 text-xs font-semibold text-white shadow-2xs hover:bg-emerald-800 transition disabled:opacity-50">
                                                    Usar fotografía
                                                </button>
                                                <button type="button" x-show="['vista_previa', 'capturada'].includes(canonEstado)" @click="cancelarCanon()" :disabled="canonProcesando"
                                                        class="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 transition disabled:opacity-50">
                                                    Cancelar
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Canal B: Archivo / Carpeta EOS Utility -->
                                        <div x-show="modoCapturaFoto === 'archivo'" class="space-y-2">
                                            <div class="rounded-lg border-2 border-dashed border-slate-300 bg-slate-50 p-4 text-center hover:bg-slate-100 transition">
                                                <input type="file"
                                                       id="archivoFotoInput"
                                                       accept="image/jpeg,image/png,image/jpg"
                                                       @change="seleccionarArchivoBiometrico('foto', $event)"
                                                       class="hidden">
                                                <label for="archivoFotoInput" class="cursor-pointer block">
                                                    <svg class="h-8 w-8 mx-auto text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                    </svg>
                                                    <span class="mt-2 block text-xs font-semibold text-inst-800">Seleccionar foto o arrastrar archivo</span>
                                                    <span class="mt-0.5 block text-2xs text-tx-soft">Compatible con carpeta de descarga de EOS Utility y cámaras USB (JPG/PNG).</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ============================================== -->
                                    <!-- SECCIÓN ACTIVA: FIRMA DIGITALIZADA            -->
                                    <!-- ============================================== -->
                                    <div x-show="slotActivo === 'firma'" class="space-y-3">
                                        <!-- Sub-pestañas de Firma -->
                                        <div class="flex items-center justify-between border-b border-slate-200 pb-1.5">
                                            <span class="text-xs font-semibold text-slate-800">2. Firma Digital</span>
                                            <div class="inline-flex rounded-md bg-slate-100 p-0.5 text-2xs">
                                                <button type="button"
                                                        @click="modoCapturaFirma = 'lienzo'; iniciarCanvasFirma();"
                                                        :class="modoCapturaFirma === 'lienzo' ? 'bg-white text-inst-900 font-bold shadow-2xs' : 'text-slate-600 hover:text-slate-900'"
                                                        class="rounded px-2.5 py-0.5 transition">Lienzo Táctil</button>
                                                <button type="button"
                                                        @click="modoCapturaFirma = 'pad'"
                                                        :class="modoCapturaFirma === 'pad' ? 'bg-white text-inst-900 font-bold shadow-2xs' : 'text-slate-600 hover:text-slate-900'"
                                                        class="rounded px-2.5 py-0.5 transition">Pad Topaz SigLite</button>
                                                <button type="button"
                                                        @click="modoCapturaFirma = 'archivo'"
                                                        :class="modoCapturaFirma === 'archivo' ? 'bg-white text-inst-900 font-bold shadow-2xs' : 'text-slate-600 hover:text-slate-900'"
                                                        class="rounded px-2.5 py-0.5 transition">Cargar Archivo</button>
                                            </div>
                                        </div>

                                        <!-- Previsualización y Validación de Firma Capturada -->
                                        <div x-show="modalFirmaPreview && firmaTempPreviewUrl" x-cloak class="rounded-lg border-2 border-inst-700 bg-white p-3 space-y-2 shadow-xs">
                                            <div class="flex items-center justify-between border-b border-slate-200 pb-1.5">
                                                <span class="text-xs font-bold text-inst-900 flex items-center gap-1.5">
                                                    <svg class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                    Previsualización de Firma Capturada
                                                </span>
                                                <span class="text-2xs font-mono text-slate-500">Canal alfa (PNG)</span>
                                            </div>
                                            <div class="relative bg-slate-50 border border-slate-200 rounded p-2 flex items-center justify-center min-h-[95px]">
                                                <img :src="firmaTempPreviewUrl" class="max-h-20 max-w-full object-contain" alt="Vista previa de firma capturada">
                                                <div class="absolute bottom-1 right-2 text-2xs text-slate-400 italic">Verificación pericial</div>
                                            </div>
                                            <div class="flex items-center justify-between pt-1">
                                                <button type="button" @click="descartarFirmaPreview()" class="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 transition">
                                                    Descartar y Redibujar
                                                </button>
                                                <button type="button" @click="aceptarFirmaPreview()" class="rounded bg-emerald-700 px-3 py-1 text-xs font-semibold text-white shadow-2xs hover:bg-emerald-800 transition">
                                                    Aceptar Firma y Preparar Slot
                                                </button>
                                            </div>
                                        </div>

                                        <div x-show="archivosSeleccionados.firma && !modalFirmaPreview" class="rounded-lg border border-emerald-300 bg-emerald-50/80 p-2 text-xs flex items-center justify-between">
                                            <div class="flex items-center gap-1.5 text-emerald-800 font-medium">
                                                <svg class="h-4 w-4 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                <span>Firma preparada para sincronizar</span>
                                            </div>
                                            <button type="button" @click="abrirPreviewFirma()" class="text-xs font-bold text-inst-700 hover:underline">Ver trazo</button>
                                        </div>

                                        <!-- Canal A: Lienzo Táctil HTML5 con Suavizado Bézier -->
                                        <div x-show="modoCapturaFirma === 'lienzo'" class="space-y-2">
                                            <div class="relative w-full rounded-lg border border-slate-300 bg-white overflow-hidden shadow-2xs">
                                                <canvas id="canvasFirmaElemento"
                                                        class="w-full h-36 bg-white cursor-crosshair touch-none select-none block"
                                                        @pointerdown="handlePointerDown($event)"
                                                        @pointermove="handlePointerMove($event)"
                                                        @pointerup="handlePointerUp($event)"
                                                        @pointerleave="handlePointerUp($event)"></canvas>
                                                
                                                <div class="absolute bottom-1.5 left-2.5 pointer-events-none text-2xs text-slate-400 italic">
                                                    Firme con lápiz óptico, pantalla táctil o ratón
                                                </div>

                                                <div class="absolute top-1.5 right-2 flex items-center gap-1">
                                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-2xs font-mono text-slate-600" x-text="firmaTrazos > 0 ? firmaTrazos + ' trazos' : 'En espera'"></span>
                                                </div>
                                            </div>

                                            <div class="flex items-center justify-between gap-2">
                                                <button type="button"
                                                        @click="limpiarFirmaCanvas()"
                                                        class="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 transition">
                                                    Limpiar trazo
                                                </button>
                                                <button type="button"
                                                        @click="confirmarFirmaCanvas()"
                                                        :disabled="firmaTrazos < 3"
                                                        class="rounded bg-inst-700 px-3 py-1 text-xs font-semibold text-white shadow-2xs hover:bg-inst-800 disabled:opacity-40 disabled:cursor-not-allowed transition">
                                                    Confirmar Firma
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Canal B: Pad Físico Topaz SigLite (Servicio Local SigWeb) -->
                                        <div x-show="modoCapturaFirma === 'pad'" class="rounded-lg border border-slate-200 bg-slate-50 p-3.5 space-y-2.5">
                                            <div class="flex items-center justify-between">
                                                <span class="text-xs font-bold text-slate-800">Controlador Topaz SigLite LCD 1x5</span>
                                                <span class="flex items-center gap-1 rounded px-2 py-0.5 text-2xs font-bold" :class="estadoPadTopaz().insignia" role="status">
                                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full" aria-hidden="true" :class="estadoPadTopaz().punto"></span>
                                                    <span x-text="estadoPadTopaz().etiqueta"></span>
                                                </span>
                                            </div>

                                            <p class="text-[11px] text-tx-soft leading-relaxed">
                                                Captura con el SDK oficial <code class="font-mono text-2xs bg-slate-200/80 px-1 py-0.5 rounded">SigWebTablet.js</code> a través del servicio local SigWeb de esta estación<span x-show="padTopazVersion" x-text="' (versión ' + padTopazVersion + ')'"></span>.
                                            </p>

                                            <div class="rounded border border-slate-300 bg-white p-1.5">
                                                {{-- El SDK dibuja aquí el trazo en vivo; las dimensiones internas son las que recibe SetDisplayXSize/YSize. --}}
                                                <canvas id="canvasTopazFirma" width="500" height="100"
                                                        class="block h-auto w-full"
                                                        :class="padTopazEstado === 'capturando' ? 'ring-2 ring-inst-500' : ''"
                                                        aria-label="Vista previa del trazo capturado en el pad Topaz"></canvas>
                                            </div>

                                            <div x-show="padTopazMensaje" class="rounded bg-white p-2 border border-slate-200 text-2xs text-slate-700" :class="padTopazEstado === 'error' ? 'border-red-300 text-red-800' : ''" x-text="padTopazMensaje" aria-live="polite"></div>

                                            <div class="flex flex-wrap items-center gap-2">
                                                <button type="button"
                                                        @click="verificarSigWeb()"
                                                        :disabled="padTopazEstado === 'capturando' || padTopazProcesando"
                                                        class="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 transition disabled:cursor-not-allowed disabled:opacity-50">
                                                    Verificar pad
                                                </button>
                                                <button type="button"
                                                        x-show="padTopazEstado !== 'capturando'"
                                                        @click="iniciarCapturaTopaz()"
                                                        :disabled="padTopazProcesando || !elementoSeleccionadoId"
                                                        class="rounded bg-inst-700 px-3 py-1 text-xs font-semibold text-white shadow-2xs hover:bg-inst-800 transition disabled:cursor-not-allowed disabled:opacity-50">
                                                    Iniciar captura
                                                </button>
                                                <template x-if="padTopazEstado === 'capturando'">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <button type="button" @click="limpiarCapturaTopaz()" :disabled="padTopazProcesando"
                                                                class="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 transition disabled:opacity-50">
                                                            Limpiar
                                                        </button>
                                                        <button type="button" @click="cancelarCapturaTopaz()" :disabled="padTopazProcesando"
                                                                class="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 transition disabled:opacity-50">
                                                            Cancelar
                                                        </button>
                                                        <button type="button" @click="aceptarCapturaTopaz()" :disabled="padTopazProcesando"
                                                                class="rounded bg-emerald-700 px-3 py-1 text-xs font-semibold text-white shadow-2xs hover:bg-emerald-800 transition disabled:opacity-50">
                                                            <span x-text="padTopazProcesando ? 'Obteniendo firma…' : 'Aceptar firma'"></span>
                                                        </button>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>

                                        <!-- Canal C: Archivo de Firma -->
                                        <div x-show="modoCapturaFirma === 'archivo'" class="space-y-2">
                                            <div class="rounded-lg border-2 border-dashed border-slate-300 bg-slate-50 p-4 text-center hover:bg-slate-100 transition">
                                                <input type="file"
                                                       id="archivoFirmaInput"
                                                       accept="image/png,image/jpeg,image/jpg"
                                                       @change="seleccionarArchivoBiometrico('firma', $event)"
                                                       class="hidden">
                                                <label for="archivoFirmaInput" class="cursor-pointer block">
                                                    <svg class="h-8 w-8 mx-auto text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                                                    </svg>
                                                    <span class="mt-2 block text-xs font-semibold text-inst-800">Seleccionar archivo de firma</span>
                                                    <span class="mt-0.5 block text-2xs text-tx-soft">Formatos admitidos: PNG transparente o JPG (máx. 2MB).</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ============================================== -->
                                    <!-- SECCIÓN ACTIVA: HUELLAS DACTILARES            -->
                                    <!-- ============================================== -->
                                    <div x-show="slotActivo === 'pulgar_izquierdo' || slotActivo === 'pulgar_derecho'" class="space-y-3">
                                        <div class="flex items-center justify-between border-b border-slate-200 pb-1.5">
                                            <span class="text-xs font-semibold text-slate-800" x-text="slotActivo === 'pulgar_izquierdo' ? '3. Huella Pulgar Izquierdo' : '4. Huella Pulgar Derecho'"></span>
                                            <span class="flex items-center gap-1 rounded px-2 py-0.5 text-2xs font-bold" :class="estadoHuella().insignia" role="status">
                                                <span class="h-1.5 w-1.5 shrink-0 rounded-full" aria-hidden="true" :class="estadoHuella().punto"></span>
                                                <span x-text="estadoHuella().etiqueta"></span>
                                            </span>
                                        </div>

                                        <!-- Captura con el lector Cross Match mediante el agente local de la estación -->
                                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3.5 space-y-2.5">
                                            <span class="text-xs font-bold text-slate-800">Cross Match Verifier 300 LC 2.0 · agente de estación</span>

                                            <div x-show="huellaSimulacion" class="rounded border border-amber-300 bg-amber-50 p-2 text-2xs font-semibold text-amber-900">
                                                Modo simulación del agente: las imágenes no provienen de un lector y no pueden guardarse en el expediente.
                                            </div>

                                            <div class="mx-auto flex aspect-[4/5] w-full max-w-[200px] items-center justify-center overflow-hidden rounded-lg border border-slate-300 bg-white">
                                                <template x-if="huellaCapturaUrl">
                                                    <img :src="huellaCapturaUrl" class="h-full w-full object-contain" alt="Huella capturada con el lector Cross Match">
                                                </template>
                                                <template x-if="!huellaCapturaUrl && huellaVistaUrl">
                                                    <img :src="huellaVistaUrl" class="h-full w-full object-contain" alt="Vista previa del lector Cross Match">
                                                </template>
                                                <template x-if="!huellaCapturaUrl && !huellaVistaUrl">
                                                    <span class="p-3 text-center text-2xs text-tx-soft" x-text="huellaEstado === 'vista_previa' ? 'Coloque el dedo sobre el cristal…' : (huellaEstado === 'inicializando' ? 'Inicializando el lector…' : 'Sin vista previa')"></span>
                                                </template>
                                            </div>

                                            <div x-show="huellaMensaje" class="rounded border bg-white p-2 text-2xs" :class="huellaEstado === 'error' ? 'border-red-300 text-red-800' : 'border-slate-200 text-slate-700'" x-text="huellaMensaje" aria-live="polite"></div>

                                            <div class="flex flex-wrap items-center gap-2">
                                                <button type="button" @click="verificarHuella()" :disabled="huellaProcesando || ['vista_previa', 'capturada', 'inicializando'].includes(huellaEstado)"
                                                        class="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 transition disabled:cursor-not-allowed disabled:opacity-50">
                                                    Verificar lector
                                                </button>
                                                <button type="button" x-show="!['vista_previa', 'capturada', 'inicializando'].includes(huellaEstado)" @click="iniciarHuella()" :disabled="huellaProcesando || !elementoSeleccionadoId"
                                                        class="rounded bg-inst-700 px-3 py-1 text-xs font-semibold text-white shadow-2xs hover:bg-inst-800 transition disabled:cursor-not-allowed disabled:opacity-50">
                                                    Iniciar captura
                                                </button>
                                                <button type="button" x-show="huellaEstado === 'vista_previa'" @click="capturarHuella()" :disabled="huellaProcesando"
                                                        class="rounded bg-rose-600 px-3 py-1 text-xs font-semibold text-white shadow-2xs hover:bg-rose-700 transition disabled:opacity-50">
                                                    Capturar huella
                                                </button>
                                                <button type="button" x-show="huellaEstado === 'capturada'" @click="repetirHuella()" :disabled="huellaProcesando"
                                                        class="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 transition disabled:opacity-50">
                                                    Repetir
                                                </button>
                                                <button type="button" x-show="huellaEstado === 'capturada' && !huellaSimulacion" @click="usarHuella()" :disabled="huellaProcesando"
                                                        class="rounded bg-emerald-700 px-3 py-1 text-xs font-semibold text-white shadow-2xs hover:bg-emerald-800 transition disabled:opacity-50">
                                                    Usar huella
                                                </button>
                                                <button type="button" x-show="['vista_previa', 'capturada'].includes(huellaEstado)" @click="cancelarHuella()" :disabled="huellaProcesando"
                                                        class="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 transition disabled:opacity-50">
                                                    Cancelar
                                                </button>
                                            </div>
                                        </div>

                                        <p class="text-2xs font-semibold uppercase tracking-wide text-tx-soft">O importe el archivo exportado por el software del lector</p>

                                        <!-- Selector / Dropzone para Huella -->
                                        <div class="rounded-lg border-2 border-dashed border-slate-300 bg-slate-50 p-4 text-center hover:bg-slate-100 transition">
                                            <input type="file"
                                                   :id="'archivoHuellaInput_' + slotActivo"
                                                   accept="image/bmp,image/png,image/jpeg,image/jpg,.bmp"
                                                   @change="seleccionarArchivoBiometrico(slotActivo, $event)"
                                                   class="hidden">
                                            <label :for="'archivoHuellaInput_' + slotActivo" class="cursor-pointer block">
                                                <svg class="h-8 w-8 mx-auto text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 004.07 9m5.93 11a21.84 21.84 0 004.5-2.5" />
                                                </svg>
                                                <span class="mt-2 block text-xs font-semibold text-inst-800">Cargar captura de huella dactilar</span>
                                                <span class="mt-0.5 block text-2xs text-tx-soft">Admite exportaciones BMP y PNG generadas por Crossmatch Verifier 300.</span>
                                            </label>
                                        </div>

                                        <div x-show="huellaPreviewUrl[slotActivo]" class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white p-2">
                                            <div class="h-16 w-12 bg-slate-100 rounded overflow-hidden border border-slate-300 shrink-0">
                                                <img :src="huellaPreviewUrl[slotActivo]" class="w-full h-full object-contain" alt="Vista previa dactilar">
                                            </div>
                                            <div class="min-w-0 text-xs">
                                                <p class="font-bold text-slate-800 truncate" x-text="archivosSeleccionados[slotActivo]?.name"></p>
                                                <p class="text-2xs text-tx-soft" x-text="archivosSeleccionados[slotActivo] ? Math.round(archivosSeleccionados[slotActivo].size / 1024) + ' KB' : ''"></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- ============================================== -->
                                <!-- CONSOLA DE SINCRONIZACIÓN Y JUSTIFICACIÓN     -->
                                <!-- ============================================== -->
                                <div x-show="pestanaEdicion === 'biometria'" class="pt-3 border-t border-slate-200 space-y-2.5 shrink-0">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-bold uppercase tracking-wider text-slate-700">Justificación Forense (Obligatoria)</span>
                                        <template x-if="archivosSeleccionados[slotActivo]">
                                            <span class="text-2xs text-emerald-700 font-semibold flex items-center gap-1">
                                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                                Archivo preparado
                                            </span>
                                        </template>
                                        <template x-if="!archivosSeleccionados[slotActivo]">
                                            <span class="text-2xs text-amber-700 font-medium">Sin archivo seleccionado</span>
                                        </template>
                                    </div>

                                    <!-- Sugerencias de Justificación Rápida -->
                                    <div class="flex flex-wrap gap-1">
                                        <button type="button"
                                                @click="motivosCambio[slotActivo] = 'Captura biométrica inicial en estación de credencialización.'"
                                                class="rounded bg-slate-100 hover:bg-slate-200 px-2 py-0.5 text-2xs text-slate-700 transition">
                                            + Captura inicial
                                        </button>
                                        <button type="button"
                                                @click="motivosCambio[slotActivo] = 'Renovación oficial de credencial pericial LOC 139.'"
                                                class="rounded bg-slate-100 hover:bg-slate-200 px-2 py-0.5 text-2xs text-slate-700 transition">
                                            + Renovación oficial
                                        </button>
                                        <button type="button"
                                                @click="motivosCambio[slotActivo] = 'Actualización por requerimiento de nitidez y calidad pericial.'"
                                                class="rounded bg-slate-100 hover:bg-slate-200 px-2 py-0.5 text-2xs text-slate-700 transition">
                                            + Calidad pericial
                                        </button>
                                    </div>

                                    <!-- Campo de Texto de Justificación con Contador Reactivo -->
                                    <div class="relative">
                                        <input type="text"
                                               x-model="motivosCambio[slotActivo]"
                                               placeholder="Escriba motivo de actualización (mínimo 5 caracteres)..."
                                               class="block w-full rounded border py-1.5 px-2.5 pr-14 text-xs text-slate-800 ring-1 ring-inset focus:ring-2 focus:ring-inst-600 transition"
                                               :class="(motivosCambio[slotActivo] || '').trim().length >= 5 ? 'border-emerald-300 ring-emerald-400/30' : 'border-slate-300 ring-slate-200'">
                                        <span class="absolute right-2 top-1/2 -translate-y-1/2 text-2xs font-mono pointer-events-none"
                                              :class="(motivosCambio[slotActivo] || '').trim().length >= 5 ? 'text-emerald-600 font-bold' : 'text-slate-400'"
                                              x-text="((motivosCambio[slotActivo] || '').trim().length) + '/5'"></span>
                                    </div>

                                    <!-- Botón de Guardado Asíncrono en Caliente -->
                                    <button type="button"
                                            @click="guardarActualizacionBiometrico(slotActivo)"
                                            :disabled="guardandoBiometrico || !archivosSeleccionados[slotActivo] || (motivosCambio[slotActivo] || '').trim().length < 5"
                                            class="w-full flex items-center justify-center gap-2 rounded-lg bg-inst-700 py-2.5 px-4 text-xs font-bold text-white shadow-sm hover:bg-inst-900 disabled:opacity-40 disabled:cursor-not-allowed transition">
                                        <template x-if="guardandoBiometrico">
                                            <svg class="h-4 w-4 animate-spin text-white" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                            </svg>
                                        </template>
                                        <span x-text="guardandoBiometrico ? 'Procesando y Versionando...' : 'Guardar y Actualizar Credencial en Caliente'"></span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    @endif

    <div class="mt-5">
        <x-card title="Requisitos previos" description="Flujo institucional de tres pasos para la emisión oficial.">
            <ol class="grid grid-cols-1 gap-3 text-sm md:grid-cols-3">
                <li class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <span class="font-semibold text-inst-900">1. Prefiltro Administrativo</span>
                    <p class="mt-1 text-xs leading-5 text-tx-soft">El elemento debe estar verificado en padrón de personal, vigencias y cupos de armamento (estatus Apto).</p>
                </li>
                <li class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <span class="font-semibold text-inst-900">2. Validación Biométrica 4/4</span>
                    <p class="mt-1 text-xs leading-5 text-tx-soft">Resolución de ámbito (Estatales o Prevención) y verificación de foto, firma y ambos pulgares.</p>
                </li>
                <li class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <span class="font-semibold text-inst-900">3. Asignación de Folio y Emisión</span>
                    <p class="mt-1 text-xs leading-5 text-tx-soft">Asignación automática de folio oficial e inmutabilidad legal de la credencial emitida.</p>
                </li>
            </ol>
        </x-card>
    </div>

    {{-- SDK oficial de Topaz (SigWeb). Sin el archivo, la captura informa "SDK no instalado" en lugar de fallar. --}}
    @if (is_file(public_path('vendor/topaz/SigWebTablet.js')))
        <script src="{{ asset('vendor/topaz/SigWebTablet.js') }}"></script>
    @endif

    <!-- Script de Alpine.js para la Cola de Impresión -->
    <script>
        function printQueue() {
            // URL temporal de cada archivo pendiente de guardar (fuera del estado reactivo de Alpine).
            const urlsPendientes = new WeakMap();
            return {
                // Miniatura de la ranura: la captura aceptada y aún no guardada tiene prioridad sobre la registrada.
                urlPendiente(slot) {
                    const archivo = this.archivosSeleccionados[slot];
                    if (!archivo) return null;
                    if (!urlsPendientes.has(archivo)) urlsPendientes.set(archivo, URL.createObjectURL(archivo));
                    return urlsPendientes.get(archivo);
                },
                init() {
                    // Una captura pertenece al elemento con el que se inició: cambiar de elemento, de canal o cerrar la página la cancela.
                    this.$watch('elementoSeleccionadoId', (nuevo, anterior) => {
                        if (nuevo === anterior) return;
                        this.cancelarCapturaTopaz('Captura cancelada: cambió el elemento seleccionado.');
                        this.descartarFirmaCapturada();
                        this.cancelarCanon('Captura cancelada: cambió el elemento seleccionado.');
                        this.descartarFotoCanon();
                        this.cancelarHuella('Captura cancelada: cambió el elemento seleccionado.');
                        this.descartarHuellaCapturada();
                    });
                    // Una huella pertenece al pulgar con el que se inició: cambiar de componente la cancela.
                    this.$watch('slotActivo', (nuevo, anterior) => {
                        if (nuevo !== anterior && this.huella?.categoria) this.cancelarHuella('Captura cancelada: cambió el componente biométrico.');
                    });
                    this.$watch('pestanaEdicion', (pestana) => {
                        if (pestana === 'biometria') return;
                        this.cancelarCanon();
                        this.cancelarHuella('Captura cancelada: se salió de la pestaña de biometría.');
                    });
                    this.$watch('modoCapturaFirma', (modo) => {
                        if (modo !== 'pad') this.cancelarCapturaTopaz();
                    });
                    this.$watch('modoCapturaFoto', (modo) => {
                        if (modo !== 'canon') this.cancelarCanon();
                    });
                    // El agente también detiene la vista previa por inactividad si la página se cierra sin avisar.
                    window.addEventListener('pagehide', () => {
                        this.topaz?.liberar();
                        this.canon?.cancelar();
                        this.huella?.cancelar();
                    });
                },
                selectedTarjeta: '',
                numeroTarjeta: '',
                isLoading: false,
                search: '',
                filtroEstado: 'todos',
                filtroAmbito: 'todos',
                mensajeExito: '',
                mensajeError: '',
                metricas: {
                    trabajos_en_cola: 0,
                    listos_para_imprimir: 0,
                    biometria_pendiente: 0,
                    impresos: 0
                },
                elementos: [],

                // Estado del Modal de Vista Previa y Biometría
                modalPrevisualizacion: false,
                elementoSeleccionadoId: null,
                versionPlantillaSeleccionada: 1,
                cargandoPrevisualizacion: false,
                previewData: null,
                modoModal: 'vista',
                pestanaEdicion: 'biometria',
                miniaturas: { foto: 'cargando', firma: 'cargando', pulgar_derecho: 'cargando', pulgar_izquierdo: 'cargando' },
                caraCredencial: 'front',
                animandoGiro: false,
                guardandoBiometrico: false,
                errorModal: '',
                exitoModal: '',
                toastMensaje: '',
                toastTipo: 'exito',
                toastTimeout: null,
                
                // Consola de Periféricos y Slots
                slotActivo: 'foto',
                modoCapturaFoto: 'camara',
                // Cámara Canon mediante el agente local (resources/js/canon-agente.js); se crea al primer uso.
                agenteEstacionUrl: @json(config('devices.agente_estacion.url')),
                canon: null,
                canonEstado: 'sin_verificar',
                canonMensaje: '',
                canonSimulacion: false,
                canonProcesando: false,
                canonVistaUrl: null,
                canonCapturaUrl: null,
                canonCaptura: null,
                // Ticket y elemento de la fotografía Canon preparada; el servidor verifica ambos al guardar.
                fotoCapturaTicket: null,
                fotoCapturaElementoId: null,
                fotoCapturaArchivo: null,
                // Lector Cross Match mediante el mismo agente (crearCapturaHuella); se crea al primer uso.
                huella: null,
                huellaEstado: 'sin_verificar',
                huellaMensaje: '',
                huellaSimulacion: false,
                huellaProcesando: false,
                huellaVistaUrl: null,
                huellaCapturaUrl: null,
                huellaCaptura: null,
                // Ticket, elemento y categoría de la huella preparada; el servidor verifica los tres al guardar.
                // Huella preparada por pulgar: { archivo, ticket, elementoId }. Cada pulgar conserva la suya hasta
                // guardarla o descartarla; capturar un pulgar no toca la del otro.
                huellasPreparadas: {},
                modoCapturaFirma: 'lienzo',
                
                // Módulo de Cámara Web / USB
                camaraActiva: false,
                camaraIniciando: false,
                camaraStream: null,
                camaraError: '',
                fotoCapturadaBlob: null,
                fotoCapturadaUrl: null,

                // Módulo Pad Topaz / Lienzo
                cambiarModoModal(modo) {
                    this.modoModal = modo;
                    if (modo === 'vista' && this.camaraActiva) {
                        this.detenerCamara();
                    }
                },
                // --- Tabla: estado por componente, mismo criterio que la validación 4/4 ---
                estadoComponente(elem, slot) {
                    const b = elem.biometricos ? elem.biometricos[slot] : null;
                    if (!b) return 'sin_verificar';
                    if (b.actualizado) return 'actualizado';
                    return b.valido ? 'completo' : 'faltante';
                },
                claseComponente(elem, slot) {
                    return ({
                        completo: 'border-emerald-600/40 bg-emerald-50 text-emerald-700',
                        faltante: 'border-dashed border-red-500 bg-red-50 text-red-700',
                        actualizado: 'border-amber-500/70 bg-amber-50 text-amber-800',
                        sin_verificar: 'border-dotted border-slate-300 bg-white text-slate-400',
                    })[this.estadoComponente(elem, slot)];
                },
                tituloComponente(elem, slot, nombre) {
                    const b = elem.biometricos ? elem.biometricos[slot] : null;
                    return ({
                        completo: nombre + ': completo',
                        faltante: nombre + ': falta o no cumple (' + this.motivoLegible(b && b.motivo) + ')',
                        actualizado: nombre + ': actualizado; ejecute la validación biométrica',
                        sin_verificar: nombre + ': sin verificar (sin expediente asociado)',
                    })[this.estadoComponente(elem, slot)];
                },
                resumenBiometria(elem) {
                    const faltantes = elem.faltantes || [];
                    if (faltantes.includes('ambito_no_resuelto')) return 'Ámbito no resuelto';
                    if (faltantes.includes('expediente_no_localizado')) return 'Expediente no localizado';
                    if (!elem.biometricos) return 'Sin verificar';
                    const slots = ['foto', 'firma', 'pulgar_derecho', 'pulgar_izquierdo'];
                    if (slots.some(s => elem.biometricos[s] && elem.biometricos[s].actualizado)) return 'Actualizado · validar';
                    const completos = slots.filter(s => elem.biometricos[s] && elem.biometricos[s].valido).length;
                    return completos === 4 ? 'Completa 4/4' : completos + '/4 completos';
                },
                claseResumenBiometria(elem) {
                    const r = this.resumenBiometria(elem);
                    if (r === 'Completa 4/4') return 'text-emerald-700';
                    if (r === 'Sin verificar') return 'text-tx-soft';
                    if (r === 'Actualizado · validar') return 'text-amber-800';
                    return 'text-red-700';
                },
                motivoLegible(motivo) {
                    if (!motivo) return 'no válido';
                    const r = String(motivo).replace(/^(foto|firma|pulgar_derecho|pulgar_izquierdo)_?/, '');
                    return ({ '': 'sin archivo', no_vigente: 'no vigente', categoria_incorrecta: 'categoría incorrecta', discrepancia_cuip: 'CUIP no coincide', discrepancia_ambito: 'ámbito no coincide', hash_no_verificado: 'hash no verificado' })[r] || r.replace(/_/g, ' ');
                },
                // --- Estación biométrica del modal ---
                estadoSlot(slot) {
                    const b = this.previewData && this.previewData.biometricos ? this.previewData.biometricos[slot] : null;
                    if (!b) return 'pendiente';
                    if (this.guardandoBiometrico && this.slotActivo === slot) return 'guardando';
                    if (this.archivosSeleccionados[slot]) return 'preparado';
                    if (b.actualizado) return 'actualizado';
                    if (!b.existe) return 'pendiente';
                    if (this.miniaturas[slot] === 'error') return 'error_carga';
                    if (b.valido === false) return 'incompleto';
                    return 'registrado';
                },
                etiquetaEstadoSlot(slot) {
                    return ({ registrado: 'Registrado', pendiente: 'Pendiente', incompleto: 'No cumple validación', preparado: 'Archivo por guardar', guardando: 'Guardando…', actualizado: 'Actualizado', error_carga: 'Error al cargar' })[this.estadoSlot(slot)];
                },
                claseEstadoSlot(slot) {
                    return ({
                        registrado: 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
                        pendiente: 'bg-red-50 text-red-800 ring-red-600/20',
                        incompleto: 'bg-amber-50 text-amber-900 ring-amber-600/30',
                        preparado: 'bg-inst-50 text-inst-800 ring-inst-600/20',
                        guardando: 'bg-inst-50 text-inst-800 ring-inst-600/20',
                        actualizado: 'bg-inst-50 text-inst-800 ring-inst-600/20',
                        error_carga: 'bg-slate-100 text-slate-700 ring-slate-500/30',
                    })[this.estadoSlot(slot)];
                },
                tituloEstadoSlot(slot) {
                    const b = this.previewData && this.previewData.biometricos ? this.previewData.biometricos[slot] : null;
                    const estado = this.estadoSlot(slot);
                    if (estado === 'incompleto') return 'No cumple la validación: ' + this.motivoLegible(b && b.motivo);
                    if (estado === 'error_carga') return 'El archivo está registrado, pero no se pudo cargar su vista previa.';
                    if (estado === 'actualizado') return 'Archivo actualizado; ejecute la validación biométrica de la tarjeta.';
                    return this.etiquetaEstadoSlot(slot);
                },
                componentesPendientes() {
                    return ['foto', 'firma', 'pulgar_derecho', 'pulgar_izquierdo'].filter(slot => this.estadoSlot(slot) !== 'registrado').length;
                },
                etiquetaTramite(estado) {
                    return ({ listo_impresion: 'Listo para impresión', pendiente_biometrico: 'Pendiente de biometría', apto: 'Apto', pendiente: 'Pendiente', descartado: 'Descartado' })[estado]
                        || (estado ? String(estado).replace(/_/g, ' ') : '—');
                },
                topaz: null,
                padTopazEstado: 'sin_verificar',
                padTopazMensaje: '',
                padTopazVersion: null,
                padTopazProcesando: false,
                // Elemento para el que se capturó la firma preparada (pad o lienzo); se envía al servidor para verificarlo.
                firmaCapturaElementoId: null,
                firmaCanvas: null,
                firmaCtx: null,
                firmaTrazos: 0,
                firmaDibujando: false,
                firmaUltimoPunto: null,
                firmaCapturadaBlob: null,
                firmaCapturadaUrl: null,
                firmaTempPreviewUrl: null,
                modalFirmaPreview: false,

                // Módulo de Huellas Dactilares
                escalaTarjeta: 1,
                huellaPreviewUrl: {
                    pulgar_izquierdo: null,
                    pulgar_derecho: null
                },

                archivosSeleccionados: {
                    foto: null,
                    firma: null,
                    pulgar_derecho: null,
                    pulgar_izquierdo: null
                },
                motivosCambio: {
                    foto: '',
                    firma: '',
                    pulgar_derecho: '',
                    pulgar_izquierdo: ''
                },
                cacheBuster: Date.now(),

                get elementosFiltrados() {
                    return this.elementos.filter(elem => {
                        const searchLower = (this.search || '').toLowerCase().trim();
                        const matchSearch = !searchLower ||
                            (elem.cuip && elem.cuip.toLowerCase().includes(searchLower)) ||
                            (elem.nombre && elem.nombre.toLowerCase().includes(searchLower)) ||
                            (elem.folio && elem.folio.toLowerCase().includes(searchLower));

                        let matchEstado = true;
                        if (this.filtroEstado === 'listo') {
                            matchEstado = elem.estado_tramite === 'listo_impresion';
                        } else if (this.filtroEstado === 'pendiente') {
                            matchEstado = elem.estado_tramite !== 'listo_impresion';
                        }

                        let matchAmbito = true;
                        if (this.filtroAmbito !== 'todos') {
                            matchAmbito = (elem.ambito_biometrico === this.filtroAmbito);
                        }

                        return matchSearch && matchEstado && matchAmbito;
                    });
                },

                async cargarElementos() {
                    if (!this.selectedTarjeta) {
                        this.elementos = [];
                        this.metricas = { trabajos_en_cola: 0, listos_para_imprimir: 0, biometria_pendiente: 0, impresos: 0 };
                        this.mensajeExito = '';
                        this.mensajeError = '';
                        return;
                    }

                    this.isLoading = true;
                    this.mensajeExito = '';
                    this.mensajeError = '';

                    try {
                        const response = await fetch(`/impresion/tarjetas/${this.selectedTarjeta}/elementos`, {
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });

                        if (!response.ok) {
                            throw new Error(`Error en el servidor al consultar la tarjeta (${response.status})`);
                        }

                        const data = await response.json();
                        if (data.success) {
                            this.numeroTarjeta = data.numero_tarjeta || '';
                            this.elementos = (data.elementos || []).map(elem => ({
                                ...elem,
                                biometria_valida: elem.estado_tramite === 'listo_impresion' ? true : (elem.tiene_expediente ? elem.es_completo : null),
                                faltantes: elem.faltantes || [],
                            }));
                            if (data.metricas) {
                                this.metricas = data.metricas;
                            }
                        } else {
                            throw new Error(data.message || 'No fue posible cargar los elementos.');
                        }
                    } catch (err) {
                        this.mensajeError = err.message;
                        this.elementos = [];
                    } finally {
                        this.isLoading = false;
                    }
                },

                async validarBiometria() {
                    if (!this.selectedTarjeta || this.isLoading) return;

                    this.isLoading = true;
                    this.mensajeExito = '';
                    this.mensajeError = '';

                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

                    try {
                        const response = await fetch(`/impresion/tarjetas/${this.selectedTarjeta}/validar-biometria`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken || '',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: JSON.stringify({})
                        });

                        const data = await response.json();

                        if (!response.ok || !data.success) {
                            throw new Error(data.message || `Error en la validación biométrica (${response.status})`);
                        }

                        // Actualizar contadores y métricas
                        if (data.metricas) {
                            this.metricas = data.metricas;
                        }

                        // Mapeo reactivo de cada elemento por ID
                        if (Array.isArray(data.detalles)) {
                            const detallesMap = new Map();
                            data.detalles.forEach(d => detallesMap.set(d.elemento_id, d));

                            this.elementos = this.elementos.map(elem => {
                                if (detallesMap.has(elem.id)) {
                                    const det = detallesMap.get(elem.id);
                                    const motivoDe = (slot) => {
                                        const f = (det.faltantes || []).find(x => String(x).startsWith(slot + ':'));
                                        return f ? String(f).split(': ').slice(1).join(': ') : null;
                                    };
                                    const biometricos = det.archivos
                                        ? Object.fromEntries(Object.entries(det.archivos).map(([slot, valido]) => [slot, { valido: !!valido, motivo: valido ? null : motivoDe(slot) }]))
                                        : null;
                                    return {
                                        ...elem,
                                        ambito_biometrico: det.ambito || elem.ambito_biometrico,
                                        biometria_valida: det.biometria_valida,
                                        folio: det.folio,
                                        estado_tramite: det.estado_tramite,
                                        faltantes: det.faltantes || [],
                                        biometricos,
                                        puede_imprimir: det.biometria_valida && !!det.folio
                                    };
                                }
                                return elem;
                            });
                        }

                        const listos = data.metricas?.listos_para_imprimir ?? 0;
                        const pendientes = data.metricas?.biometria_pendiente ?? 0;
                        this.mensajeExito = `Validación biométrica finalizada: ${listos} elemento(s) listos con folio asignado, ${pendientes} con faltantes o pendientes.`;
                    } catch (err) {
                        this.mensajeError = err.message;
                    } finally {
                        this.isLoading = false;
                    }
                },

                async abrirModalPrevisualizacion(elementoId, templateVer = null, modo = null) {
                    this.elementoSeleccionadoId = elementoId;
                    if (modo) {
                        this.modoModal = modo;
                        this.pestanaEdicion = 'biometria';
                    }
                    this.miniaturas = { foto: 'cargando', firma: 'cargando', pulgar_derecho: 'cargando', pulgar_izquierdo: 'cargando' };
                    if (templateVer !== null) {
                        this.versionPlantillaSeleccionada = templateVer;
                    }
                    this.modalPrevisualizacion = true;
                    this.cargandoPrevisualizacion = true;
                    this.errorModal = '';
                    this.exitoModal = '';
                    this.toastMensaje = '';
                    this.animandoGiro = false;
                    this.caraCredencial = 'front';
                    this.slotActivo = 'foto';
                    this.archivosSeleccionados = { foto: null, firma: null, pulgar_derecho: null, pulgar_izquierdo: null };
                    this.motivosCambio = { foto: '', firma: '', pulgar_derecho: '', pulgar_izquierdo: '' };

                    try {
                        const verParam = this.versionPlantillaSeleccionada ? `?template_version=${this.versionPlantillaSeleccionada}` : '';
                        const response = await fetch(`/impresion/elementos/${elementoId}/previsualizacion${verParam}`, {
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });

                        if (!response.ok) {
                            const errJson = await response.json().catch(() => ({}));
                            throw new Error(errJson.message || `Error al obtener la previsualización (${response.status})`);
                        }

                        const data = await response.json();
                        if (data.success) {
                            this.previewData = data;
                            this.cacheBuster = Date.now();
                            // En edición se abre directamente el primer componente que requiere atención.
                            if (this.modoModal === 'edicion') {
                                const pendiente = ['foto', 'firma', 'pulgar_derecho', 'pulgar_izquierdo'].find(slot => this.estadoSlot(slot) !== 'registrado');
                                if (pendiente) {
                                    this.$nextTick(() => this.cambiarSlot(pendiente));
                                }
                            }
                        } else {
                            throw new Error(data.message || 'No fue posible cargar los datos de la credencial.');
                        }
                    } catch (err) {
                        this.errorModal = err.message;
                        this.mostrarToast('error', err.message);
                        this.previewData = null;
                    } finally {
                        this.cargandoPrevisualizacion = false;
                    }
                },

                cambiarVersionPlantilla(ver) {
                    if (this.versionPlantillaSeleccionada === ver) return;
                    this.versionPlantillaSeleccionada = ver;
                    if (this.elementoSeleccionadoId) {
                        this.abrirModalPrevisualizacion(this.elementoSeleccionadoId, ver);
                    }
                },

                alternarCara(cara) {
                    if (this.caraCredencial === cara) return;
                    this.animandoGiro = true;
                    setTimeout(() => {
                        this.caraCredencial = cara;
                        setTimeout(() => {
                            this.animandoGiro = false;
                        }, 120);
                    }, 80);
                },

                mostrarToast(tipo, mensaje) {
                    this.toastTipo = tipo;
                    this.toastMensaje = mensaje;
                    if (this.toastTimeout) {
                        clearTimeout(this.toastTimeout);
                    }
                    this.toastTimeout = setTimeout(() => {
                        this.toastMensaje = '';
                    }, 4500);
                },

                copiarHash(hash) {
                    if (!hash) return;
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(hash).then(() => {
                            this.mostrarToast('exito', `Hash SHA-256 copiado: ${hash.substring(0, 16)}...`);
                        }).catch(() => {
                            this.mostrarToast('error', 'No se pudo copiar el hash.');
                        });
                    } else {
                        this.mostrarToast('exito', `Hash: ${hash}`);
                    }
                },

                cambiarSlot(slot) {
                    if (this.slotActivo === 'foto' && slot !== 'foto' && this.camaraActiva) {
                        this.detenerCamara();
                    }
                    if (this.slotActivo === 'foto' && slot !== 'foto') {
                        this.cancelarCanon();
                    }
                    this.slotActivo = slot;
                    if (slot === 'firma' && this.modoCapturaFirma === 'lienzo') {
                        this.$nextTick(() => this.iniciarCanvasFirma());
                    }
                },

                async iniciarCamara() {
                    this.camaraError = '';
                    this.camaraIniciando = true;
                    try {
                        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                            throw new Error('Su navegador no soporta la API de captura de video en vivo (getUserMedia).');
                        }

                        const stream = await navigator.mediaDevices.getUserMedia({
                            video: {
                                width: { ideal: 1280 },
                                height: { ideal: 720 },
                                facingMode: 'user'
                            },
                            audio: false
                        });

                        this.camaraStream = stream;
                        this.camaraActiva = true;

                        this.$nextTick(() => {
                            const video = document.getElementById('videoCapturaFacial');
                            if (video) {
                                video.srcObject = stream;
                                video.play().catch(() => {});
                            }
                        });
                    } catch (err) {
                        this.camaraError = 'No fue posible acceder a la cámara: ' + (err.message || 'Permiso denegado.');
                        this.camaraActiva = false;
                    } finally {
                        this.camaraIniciando = false;
                    }
                },

                detenerCamara() {
                    if (this.camaraStream) {
                        this.camaraStream.getTracks().forEach(t => t.stop());
                        this.camaraStream = null;
                    }
                    const video = document.getElementById('videoCapturaFacial');
                    if (video) {
                        video.srcObject = null;
                    }
                    this.camaraActiva = false;
                },

                capturarFoto() {
                    const video = document.getElementById('videoCapturaFacial');
                    if (!video || !this.camaraActiva) return;

                    const canvas = this.recortarRetrato(video, video.videoWidth || 1280, video.videoHeight || 720, 600);

                    canvas.toBlob((blob) => {
                        if (!blob) return;
                        this.fotoCapturadaBlob = blob;
                        if (this.fotoCapturadaUrl) {
                            URL.revokeObjectURL(this.fotoCapturadaUrl);
                        }
                        this.fotoCapturadaUrl = URL.createObjectURL(blob);
                        const file = new File([blob], `captura_facial_${Date.now()}.jpg`, { type: 'image/jpeg' });
                        this.archivosSeleccionados.foto = file;
                        if (!this.motivosCambio.foto) {
                            this.motivosCambio.foto = 'Captura fotográfica facial pericial mediante cámara de estación.';
                        }
                        this.detenerCamara();
                        this.exitoModal = 'Fotografía facial capturada exitosamente con proporción pericial 3:4.';
                        this.mostrarToast('exito', 'Fotografía facial capturada con proporción 3:4.');
                    }, 'image/jpeg', 0.95);
                },

                descartarFotoCapturada() {
                    if (this.fotoCapturadaUrl) {
                        URL.revokeObjectURL(this.fotoCapturadaUrl);
                    }
                    this.fotoCapturadaBlob = null;
                    this.fotoCapturadaUrl = null;
                    this.archivosSeleccionados.foto = null;
                    this.iniciarCamara();
                },

                // Recorte central 3:4 pericial. Con anchoMaximo se escala a ese ancho; sin él se conserva la resolución nativa.
                recortarRetrato(fuente, anchoFuente, altoFuente, anchoMaximo = null) {
                    let cropW = altoFuente * (3 / 4);
                    let cropH = altoFuente;
                    if (cropW > anchoFuente) {
                        cropW = anchoFuente;
                        cropH = anchoFuente * (4 / 3);
                    }
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.round(anchoMaximo ?? cropW);
                    canvas.height = Math.round(canvas.width * (4 / 3));
                    canvas.getContext('2d').drawImage(fuente, (anchoFuente - cropW) / 2, (altoFuente - cropH) / 2, cropW, cropH, 0, 0, canvas.width, canvas.height);
                    return canvas;
                },

                // ----------------------------------------------------
                // Cámara Canon EOS mediante el agente local de la estación
                // ----------------------------------------------------
                capturaCanon() {
                    if (!this.canon && typeof window.crearCapturaCanon === 'function') {
                        this.canon = window.crearCapturaCanon({
                            agenteUrl: this.agenteEstacionUrl,
                            obtenerTicket: (elementoId) => this.obtenerTicketCaptura(elementoId),
                        });
                    }
                    return this.canon;
                },

                sincronizarCanon(mensaje = null) {
                    const canon = this.canon;
                    this.canonEstado = canon ? canon.estado : 'error';
                    this.canonMensaje = mensaje ?? (canon ? canon.mensaje : 'El módulo de captura Canon no se cargó en esta página.');
                    this.canonSimulacion = canon?.simulacion ?? false;
                },

                estadoCanon() {
                    const aviso = { texto: 'text-amber-800', punto: 'bg-amber-500', insignia: 'bg-amber-100 text-amber-800' };
                    const estados = {
                        sin_verificar: { etiqueta: 'Sin verificar', texto: 'text-tx-soft', punto: 'bg-slate-400', insignia: 'bg-slate-100 text-slate-700' },
                        agente_no_disponible: { etiqueta: 'Agente no disponible', ...aviso },
                        agente_no_configurado: { etiqueta: 'Agente no configurado', ...aviso },
                        sdk_no_instalado: { etiqueta: 'SDK Canon no instalado', ...aviso },
                        camara_desconectada: { etiqueta: 'Cámara desconectada', ...aviso },
                        lista: { etiqueta: this.canonSimulacion ? 'Lista (simulación)' : 'Cámara lista', texto: 'text-emerald-700', punto: 'bg-emerald-500', insignia: 'bg-emerald-100 text-emerald-800' },
                        vista_previa: { etiqueta: 'Vista previa', texto: 'text-inst-700', punto: 'bg-inst-500 animate-pulse', insignia: 'bg-blue-100 text-blue-800' },
                        capturada: { etiqueta: 'Capturada', texto: 'text-inst-700', punto: 'bg-inst-500', insignia: 'bg-blue-100 text-blue-800' },
                        error: { etiqueta: 'Error', texto: 'text-red-700', punto: 'bg-red-500', insignia: 'bg-red-100 text-red-800' },
                    };
                    return estados[this.canonEstado] ?? estados.sin_verificar;
                },

                async obtenerTicketCaptura(elementoId, categoria = null) {
                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                    const response = await fetch(`/impresion/elementos/${elementoId}/captura-ticket`, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken || '', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify(categoria ? { categoria } : {}),
                    });
                    const data = await response.json().catch(() => ({}));
                    if (!response.ok || !data.success || !data.ticket) {
                        const error = new Error(data.message || 'No fue posible autorizar la captura en el servidor.');
                        error.codigo = data.codigo || 'error';
                        throw error;
                    }
                    return { ticket: data.ticket };
                },

                async verificarCanon() {
                    const canon = this.capturaCanon();
                    if (!canon) return this.sincronizarCanon();
                    this.canonProcesando = true;
                    await canon.verificar();
                    this.canonProcesando = false;
                    this.sincronizarCanon();
                },

                async iniciarCanon() {
                    const canon = this.capturaCanon();
                    if (!canon) return this.sincronizarCanon();
                    this.descartarFotoCanon();
                    this.canonProcesando = true;
                    try {
                        await canon.iniciar(this.elementoSeleccionadoId);
                        this.sincronizarCanon();
                        canon.vigilar((blob) => {
                            if (this.canonVistaUrl) URL.revokeObjectURL(this.canonVistaUrl);
                            this.canonVistaUrl = URL.createObjectURL(blob);
                        });
                    } catch (err) {
                        this.sincronizarCanon(err.message);
                    } finally {
                        this.canonProcesando = false;
                    }
                },

                async capturarCanon() {
                    const canon = this.canon;
                    if (!canon) return;
                    this.canonProcesando = true;
                    try {
                        const resultado = await canon.capturar();
                        // El operador pudo cambiar de elemento mientras el agente entregaba la imagen.
                        if (resultado.elementoId !== this.elementoSeleccionadoId) {
                            await this.cancelarCanon('La fotografía se capturó para otro elemento y se descartó. Repita la captura.');
                            return;
                        }
                        const bitmap = await createImageBitmap(resultado.blob);
                        const canvas = this.recortarRetrato(bitmap, bitmap.width, bitmap.height);
                        bitmap.close();
                        // PNG: el servidor decodifica y verifica la imagen completa (su GD puede no leer JPEG).
                        const recorte = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
                        if (!recorte) throw new Error('No fue posible preparar la fotografía capturada.');
                        this.canonCaptura = { ...resultado, blob: recorte, ancho: canvas.width, alto: canvas.height };
                        if (this.canonCapturaUrl) URL.revokeObjectURL(this.canonCapturaUrl);
                        this.canonCapturaUrl = URL.createObjectURL(recorte);
                        this.sincronizarCanon();
                    } catch (err) {
                        this.sincronizarCanon(err.message);
                    } finally {
                        this.canonProcesando = false;
                    }
                },

                async repetirCanon() {
                    if (!this.canon) return;
                    this.descartarFotoCanon();
                    try {
                        await this.canon.repetir();
                        this.sincronizarCanon();
                        this.canon.vigilar((blob) => {
                            if (this.canonVistaUrl) URL.revokeObjectURL(this.canonVistaUrl);
                            this.canonVistaUrl = URL.createObjectURL(blob);
                        });
                    } catch (err) {
                        this.sincronizarCanon(err.message);
                    }
                },

                // Devuelve true si había una sesión del agente abierta.
                async cancelarCanon(mensaje = null) {
                    if (!this.canon) return false;
                    const habia = await this.canon.cancelar(mensaje);
                    if (this.canonVistaUrl) URL.revokeObjectURL(this.canonVistaUrl);
                    this.canonVistaUrl = null;
                    if (this.canonCapturaUrl) URL.revokeObjectURL(this.canonCapturaUrl);
                    this.canonCapturaUrl = null;
                    this.canonCaptura = null;
                    if (habia) this.sincronizarCanon(mensaje);
                    return habia;
                },

                // Pasa la fotografía revisada al flujo de actualización existente (botón de guardar y justificación).
                usarFotoCanon() {
                    const captura = this.canonCaptura;
                    if (!captura) return;
                    if (captura.simulacion) {
                        this.sincronizarCanon('Una imagen del modo simulación no puede guardarse en el expediente.');
                        return;
                    }
                    if (captura.elementoId !== this.elementoSeleccionadoId) {
                        this.cancelarCanon('La fotografía se capturó para otro elemento y se descartó. Repita la captura.');
                        return;
                    }
                    this.fotoCapturaArchivo = new File([captura.blob], `canon_${Date.now()}.png`, { type: 'image/png' });
                    this.archivosSeleccionados.foto = this.fotoCapturaArchivo;
                    this.fotoCapturaTicket = captura.ticket;
                    this.fotoCapturaElementoId = captura.elementoId;
                    this.motivosCambio.foto = 'Fotografía facial capturada con cámara Canon EOS mediante el agente de estación.';
                    this.sincronizarCanon('Fotografía preparada. Guárdela para registrarla en el expediente.');
                },

                // Descarta la fotografía Canon preparada (no toca la de la cámara web ni la de archivo).
                descartarFotoCanon() {
                    if (this.fotoCapturaArchivo && this.archivosSeleccionados.foto === this.fotoCapturaArchivo) {
                        this.archivosSeleccionados.foto = null;
                    }
                    this.fotoCapturaArchivo = null;
                    this.fotoCapturaTicket = null;
                    this.fotoCapturaElementoId = null;
                    if (this.canonCapturaUrl) URL.revokeObjectURL(this.canonCapturaUrl);
                    this.canonCapturaUrl = null;
                    this.canonCaptura = null;
                },

                // ----------------------------------------------------
                // Lector Cross Match mediante el agente local de la estación
                // ----------------------------------------------------
                capturaHuella() {
                    if (!this.huella && typeof window.crearCapturaHuella === 'function') {
                        this.huella = window.crearCapturaHuella({
                            agenteUrl: this.agenteEstacionUrl,
                            obtenerTicket: (elementoId, categoria) => this.obtenerTicketCaptura(elementoId, categoria),
                        });
                    }
                    return this.huella;
                },

                sincronizarHuella(mensaje = null) {
                    const huella = this.huella;
                    this.huellaEstado = huella ? huella.estado : 'error';
                    this.huellaMensaje = mensaje ?? (huella ? huella.mensaje : 'El módulo de captura de huellas no se cargó en esta página.');
                    this.huellaSimulacion = huella?.simulacion ?? false;
                },

                estadoHuella() {
                    const aviso = { texto: 'text-amber-800', punto: 'bg-amber-500', insignia: 'bg-amber-100 text-amber-800' };
                    const activo = { texto: 'text-inst-700', insignia: 'bg-blue-100 text-blue-800' };
                    const estados = {
                        sin_verificar: { etiqueta: 'Sin verificar', texto: 'text-tx-soft', punto: 'bg-slate-400', insignia: 'bg-slate-100 text-slate-700' },
                        agente_no_disponible: { etiqueta: 'Agente no disponible', ...aviso },
                        agente_no_configurado: { etiqueta: 'Servidor sin configurar', ...aviso },
                        sdk_no_instalado: { etiqueta: 'SDK no instalado', ...aviso },
                        sdk_incompatible: { etiqueta: 'SDK incompatible', ...aviso },
                        lector_desconectado: { etiqueta: 'Lector desconectado', ...aviso },
                        en_uso: { etiqueta: 'Lector en uso', ...aviso },
                        error_inicializacion: { etiqueta: 'Error de inicialización', texto: 'text-red-700', punto: 'bg-red-500', insignia: 'bg-red-100 text-red-800' },
                        lista: { etiqueta: this.huellaSimulacion ? 'Disponible (simulación)' : 'Lector disponible', texto: 'text-emerald-700', punto: 'bg-emerald-500', insignia: 'bg-emerald-100 text-emerald-800' },
                        inicializando: { etiqueta: 'Inicializando…', punto: 'bg-inst-500 animate-pulse', ...activo },
                        vista_previa: { etiqueta: 'Capturando', punto: 'bg-inst-500 animate-pulse', ...activo },
                        capturada: { etiqueta: 'Huella capturada', punto: 'bg-inst-500', ...activo },
                        cancelado: { etiqueta: 'Cancelado', texto: 'text-tx-soft', punto: 'bg-slate-400', insignia: 'bg-slate-100 text-slate-700' },
                        error: { etiqueta: 'Error', texto: 'text-red-700', punto: 'bg-red-500', insignia: 'bg-red-100 text-red-800' },
                    };
                    return estados[this.huellaEstado] ?? estados.sin_verificar;
                },

                async verificarHuella() {
                    const huella = this.capturaHuella();
                    if (!huella) return this.sincronizarHuella();
                    this.huellaProcesando = true;
                    this.huellaEstado = 'inicializando';
                    await huella.verificar();
                    this.huellaProcesando = false;
                    this.sincronizarHuella();
                },

                async iniciarHuella() {
                    const huella = this.capturaHuella();
                    if (!huella) return this.sincronizarHuella();
                    const categoria = this.slotActivo;
                    if (categoria !== 'pulgar_derecho' && categoria !== 'pulgar_izquierdo') return;
                    // Recapturar reemplaza solo la huella preparada de ESTE pulgar; la del otro se conserva.
                    this.descartarHuellaCapturada(categoria);
                    this.huellaProcesando = true;
                    this.huellaEstado = 'inicializando';
                    try {
                        await huella.iniciar(this.elementoSeleccionadoId, categoria);
                        this.sincronizarHuella();
                        huella.vigilar((blob) => {
                            if (this.huellaVistaUrl) URL.revokeObjectURL(this.huellaVistaUrl);
                            this.huellaVistaUrl = URL.createObjectURL(blob);
                        });
                    } catch (err) {
                        this.sincronizarHuella(err.message);
                    } finally {
                        this.huellaProcesando = false;
                    }
                },

                async capturarHuella() {
                    const huella = this.huella;
                    if (!huella) return;
                    this.huellaProcesando = true;
                    try {
                        const resultado = await huella.capturar();
                        // El operador pudo cambiar de elemento o de pulgar mientras el lector entregaba la imagen.
                        if (resultado.elementoId !== this.elementoSeleccionadoId || resultado.categoria !== this.slotActivo) {
                            await this.cancelarHuella('La huella se capturó para otro elemento o pulgar y se descartó. Repita la captura.');
                            return;
                        }
                        this.huellaCaptura = resultado;
                        if (this.huellaCapturaUrl) URL.revokeObjectURL(this.huellaCapturaUrl);
                        this.huellaCapturaUrl = URL.createObjectURL(resultado.blob);
                        this.sincronizarHuella();
                    } catch (err) {
                        this.sincronizarHuella(err.message);
                    } finally {
                        this.huellaProcesando = false;
                    }
                },

                async repetirHuella() {
                    if (!this.huella) return;
                    if (this.huellaCapturaUrl) URL.revokeObjectURL(this.huellaCapturaUrl);
                    this.huellaCapturaUrl = null;
                    this.huellaCaptura = null;
                    try {
                        await this.huella.repetir();
                        this.sincronizarHuella();
                        this.huella.vigilar((blob) => {
                            if (this.huellaVistaUrl) URL.revokeObjectURL(this.huellaVistaUrl);
                            this.huellaVistaUrl = URL.createObjectURL(blob);
                        });
                    } catch (err) {
                        this.sincronizarHuella(err.message);
                    }
                },

                // Devuelve true si había una sesión del lector abierta (el agente libera el lector al cerrarla).
                async cancelarHuella(mensaje = null) {
                    if (!this.huella) return false;
                    const habia = await this.huella.cancelar(mensaje);
                    if (this.huellaVistaUrl) URL.revokeObjectURL(this.huellaVistaUrl);
                    this.huellaVistaUrl = null;
                    if (this.huellaCapturaUrl) URL.revokeObjectURL(this.huellaCapturaUrl);
                    this.huellaCapturaUrl = null;
                    this.huellaCaptura = null;
                    if (habia) this.sincronizarHuella(mensaje);
                    return habia;
                },

                // Pasa la huella revisada al flujo de actualización existente (botón de guardar y justificación).
                usarHuella() {
                    const captura = this.huellaCaptura;
                    if (!captura) return;
                    if (captura.simulacion) {
                        this.sincronizarHuella('Una imagen del modo simulación no puede guardarse en el expediente.');
                        return;
                    }
                    if (captura.elementoId !== this.elementoSeleccionadoId || captura.categoria !== this.slotActivo) {
                        this.cancelarHuella('La huella se capturó para otro elemento o pulgar y se descartó. Repita la captura.');
                        return;
                    }
                    const categoria = captura.categoria;
                    const archivo = new File([captura.blob], `crossmatch_${categoria}_${Date.now()}.png`, { type: 'image/png' });
                    this.archivosSeleccionados[categoria] = archivo;
                    if (this.huellaPreviewUrl[categoria]) URL.revokeObjectURL(this.huellaPreviewUrl[categoria]);
                    this.huellaPreviewUrl[categoria] = URL.createObjectURL(captura.blob);
                    this.huellasPreparadas = { ...this.huellasPreparadas, [categoria]: { archivo, ticket: captura.ticket, elementoId: captura.elementoId } };
                    this.motivosCambio[categoria] = `Captura dactilar de ${categoria === 'pulgar_derecho' ? 'pulgar derecho' : 'pulgar izquierdo'} con lector Cross Match Verifier 300 LC 2.0 mediante el agente de estación.`;
                    this.sincronizarHuella('Huella preparada. Guárdela para registrarla en el expediente.');
                },

                // Descarta la huella del lector preparada de un pulgar, o de ambos si no se indica
                // (no toca un archivo importado manualmente).
                descartarHuellaCapturada(categoria = null) {
                    const restantes = { ...this.huellasPreparadas };
                    for (const c of categoria ? [categoria] : Object.keys(restantes)) {
                        const preparada = restantes[c];
                        if (preparada && this.archivosSeleccionados[c] === preparada.archivo) {
                            this.archivosSeleccionados[c] = null;
                            if (this.huellaPreviewUrl[c]) URL.revokeObjectURL(this.huellaPreviewUrl[c]);
                            this.huellaPreviewUrl[c] = null;
                        }
                        delete restantes[c];
                    }
                    this.huellasPreparadas = restantes;
                    if (this.huellaCapturaUrl) URL.revokeObjectURL(this.huellaCapturaUrl);
                    this.huellaCapturaUrl = null;
                    this.huellaCaptura = null;
                },

                // ----------------------------------------------------
                // Módulo de Firma (Lienzo Táctil Bézier + Topaz SigWeb)
                // ----------------------------------------------------
                iniciarCanvasFirma() {
                    this.$nextTick(() => {
                        const canvas = document.getElementById('canvasFirmaElemento');
                        if (!canvas) return;
                        this.firmaCanvas = canvas;
                        const rect = canvas.getBoundingClientRect();
                        const dpr = window.devicePixelRatio || 1;
                        canvas.width = rect.width * dpr;
                        canvas.height = rect.height * dpr;
                        const ctx = canvas.getContext('2d');
                        ctx.scale(dpr, dpr);
                        ctx.lineWidth = 2.5;
                        ctx.lineCap = 'round';
                        ctx.lineJoin = 'round';
                        ctx.strokeStyle = '#0f172a';
                        this.firmaCtx = ctx;
                    });
                },

                handlePointerDown(e) {
                    if (!this.firmaCanvas || !this.firmaCtx) return;
                    this.firmaCanvas.setPointerCapture(e.pointerId);
                    this.firmaDibujando = true;
                    const rect = this.firmaCanvas.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    const y = e.clientY - rect.top;
                    this.firmaUltimoPunto = { x, y };
                    this.firmaCtx.beginPath();
                    this.firmaCtx.moveTo(x, y);
                },

                handlePointerMove(e) {
                    if (!this.firmaDibujando || !this.firmaUltimoPunto || !this.firmaCtx) return;
                    const rect = this.firmaCanvas.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    const y = e.clientY - rect.top;

                    const midX = (this.firmaUltimoPunto.x + x) / 2;
                    const midY = (this.firmaUltimoPunto.y + y) / 2;
                    this.firmaCtx.quadraticCurveTo(this.firmaUltimoPunto.x, this.firmaUltimoPunto.y, midX, midY);
                    this.firmaCtx.stroke();

                    this.firmaUltimoPunto = { x, y };
                    this.firmaTrazos++;
                },

                handlePointerUp(e) {
                    if (!this.firmaDibujando) return;
                    this.firmaDibujando = false;
                    this.firmaUltimoPunto = null;
                    if (this.firmaCanvas && e.pointerId) {
                        try { this.firmaCanvas.releasePointerCapture(e.pointerId); } catch (_) {}
                    }
                },

                getTextContent(el) {
                    if (el.kind === 'static') {
                        return el.content || '';
                    }
                    if (el.kind === 'dynamic') {
                        const val = (this.previewData && this.previewData.campos_valores && this.previewData.campos_valores[el.field]) || '—';
                        if (el.label) {
                            return el.label + '\n' + val;
                        }
                        return val;
                    }
                    return el.content || '';
                },

                styleCardElement(el) {
                    // Igual que la vista térmica: geometría en %, tipografía heredada por el span.
                    return {
                        left: el.x + '%',
                        top: el.y + '%',
                        width: el.width + '%',
                        height: el.height + '%',
                        zIndex: (el.kind === 'image' ? 10 : 20),
                        border: el.border || 'none',
                        boxSizing: 'border-box',
                        fontWeight: el.weight || 400,
                        color: el.color || '#111827',
                        textAlign: el.align || 'center',
                        lineHeight: el.line_height || 1,
                    };
                },

                styleSpanText(el) {
                    const rotation = Number(el.rotation || 0);
                    const width = parseFloat(el.width) || 1;
                    const height = parseFloat(el.height) || 1;
                    const isRotated = rotation !== 0;
                    const isCode = (el.field === 'folio' || el.field === 'cuip');
                    const aspect = Number(this.previewData.template.height_mm) / Number(this.previewData.template.width_mm);

                    return {
                        width: isRotated ? ((height / width) * aspect * 100).toFixed(2) + '%' : '100%',
                        height: isRotated ? ((width / height) / aspect * 100).toFixed(2) + '%' : '100%',
                        transform: 'translate(-50%, -50%)' + (isRotated ? ` rotate(${rotation}deg)` : ''),
                        fontFamily: el.font_family || (isCode ? 'monospace' : 'Inter, sans-serif'),
                        fontSize: el.font_size + 'pt',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: el.align === 'left' ? 'flex-start' : (el.align === 'right' ? 'flex-end' : 'center'),
                        whiteSpace: isCode ? 'nowrap' : 'pre-line',
                    };
                },

                // Escala el lienzo físico al visor y aplica el mismo ajuste de texto que la impresión.
                ajustarTarjeta() {
                    const canvas = document.querySelector('[data-card-canvas]');
                    const visor = canvas?.parentElement;
                    if (!canvas || !visor || !canvas.offsetWidth || !visor.clientWidth) return;
                    this.escalaTarjeta = visor.clientWidth / canvas.offsetWidth;
                    canvas.querySelectorAll('.credential-print-element').forEach((el) => {
                        window.fitCredentialText?.(el, el.dataset.fontSize);
                    });
                },

                limpiarFirmaCanvas() {
                    if (!this.firmaCanvas || !this.firmaCtx) return;
                    const rect = this.firmaCanvas.getBoundingClientRect();
                    this.firmaCtx.clearRect(0, 0, rect.width, rect.height);
                    this.firmaTrazos = 0;
                    this.firmaCapturadaBlob = null;
                    if (this.firmaCapturadaUrl) {
                        URL.revokeObjectURL(this.firmaCapturadaUrl);
                        this.firmaCapturadaUrl = null;
                    }
                    this.firmaTempPreviewUrl = null;
                    this.modalFirmaPreview = false;
                    this.archivosSeleccionados.firma = null;
                },

                confirmarFirmaCanvas() {
                    if (!this.firmaCanvas || this.firmaTrazos < 3) {
                        this.errorModal = 'Por favor dibuje una firma pericial válida antes de confirmar.';
                        this.mostrarToast('error', 'Por favor dibuje una firma pericial válida antes de confirmar.');
                        return;
                    }

                    this.firmaCanvas.toBlob((blob) => {
                        if (!blob) return;
                        this.firmaCapturadaBlob = blob;
                        this.firmaCapturaElementoId = this.elementoSeleccionadoId;
                        if (this.firmaCapturadaUrl) {
                            URL.revokeObjectURL(this.firmaCapturadaUrl);
                        }
                        this.firmaCapturadaUrl = URL.createObjectURL(blob);
                        this.firmaTempPreviewUrl = this.firmaCapturadaUrl;
                        this.modalFirmaPreview = true;
                    }, 'image/png');
                },

                aceptarFirmaPreview() {
                    if (!this.firmaCapturadaBlob) return;
                    const file = new File([this.firmaCapturadaBlob], `firma_digital_${Date.now()}.png`, { type: 'image/png' });
                    this.archivosSeleccionados.firma = file;
                    if (!this.motivosCambio.firma) {
                        this.motivosCambio.firma = 'Firma digitalizada capturada en estación mediante panel táctil.';
                    }
                    this.modalFirmaPreview = false;
                    this.exitoModal = 'Firma digital capturada y lista para actualizar.';
                    this.mostrarToast('exito', 'Firma digital confirmada y lista para sincronizar.');
                },

                descartarFirmaPreview() {
                    this.descartarFirmaCapturada();
                    this.limpiarFirmaCanvas();
                },

                // Descarta la firma preparada sin depender de que el lienzo táctil exista (la del pad no lo usa).
                descartarFirmaCapturada() {
                    this.liberarPreviewFirmaArchivo();
                    if (this.firmaCapturadaUrl) URL.revokeObjectURL(this.firmaCapturadaUrl);
                    this.firmaCapturadaUrl = null;
                    this.firmaCapturadaBlob = null;
                    this.modalFirmaPreview = false;
                    this.archivosSeleccionados.firma = null;
                    this.firmaCapturaElementoId = null;
                },

                abrirPreviewFirma() {
                    this.liberarPreviewFirmaArchivo();
                    if (this.firmaCapturadaUrl) {
                        this.firmaTempPreviewUrl = this.firmaCapturadaUrl;
                    } else if (this.archivosSeleccionados.firma) {
                        this.firmaTempPreviewUrl = URL.createObjectURL(this.archivosSeleccionados.firma);
                    }
                    this.modalFirmaPreview = true;
                },

                // Libera solo la URL creada para un archivo; la de la captura tiene su propio ciclo de vida.
                liberarPreviewFirmaArchivo() {
                    if (this.firmaTempPreviewUrl && this.firmaTempPreviewUrl !== this.firmaCapturadaUrl) {
                        URL.revokeObjectURL(this.firmaTempPreviewUrl);
                    }
                    this.firmaTempPreviewUrl = null;
                },

                // Reduce fotografías de cámara réflex (varios MB) antes de subirlas; las huellas y firmas no se recomprimen.
                async redimensionarFoto(file, ladoMaximo = 1600, calidad = 0.92) {
                    const bitmap = await createImageBitmap(file);
                    const escala = Math.min(1, ladoMaximo / Math.max(bitmap.width, bitmap.height));
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.round(bitmap.width * escala);
                    canvas.height = Math.round(bitmap.height * escala);
                    canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                    bitmap.close();
                    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', calidad));
                    if (!blob) throw new Error('No fue posible preparar la fotografía.');
                    return new File([blob], file.name.replace(/\.[^.]+$/, '') + '_preparada.jpg', { type: 'image/jpeg' });
                },

                // Adaptador del SDK oficial (resources/js/topaz-signature.js); se crea al primer uso.
                padTopaz() {
                    if (!this.topaz && typeof window.crearCapturaTopaz === 'function') {
                        this.topaz = window.crearCapturaTopaz();
                    }
                    return this.topaz;
                },

                sincronizarEstadoTopaz(mensaje = null) {
                    const topaz = this.topaz;
                    this.padTopazEstado = topaz ? topaz.estado : 'error';
                    this.padTopazMensaje = mensaje ?? (topaz ? topaz.mensaje : 'El módulo de captura Topaz no se cargó en esta página.');
                    this.padTopazVersion = topaz?.version ?? null;
                },

                estadoPadTopaz() {
                    const estados = {
                        sin_verificar: { etiqueta: 'Sin verificar', texto: 'text-tx-soft', punto: 'bg-slate-400', insignia: 'bg-slate-100 text-slate-700' },
                        sdk_ausente: { etiqueta: 'SDK no instalado', texto: 'text-amber-800', punto: 'bg-amber-500', insignia: 'bg-amber-100 text-amber-800' },
                        sdk_incompatible: { etiqueta: 'SDK incompatible', texto: 'text-amber-800', punto: 'bg-amber-500', insignia: 'bg-amber-100 text-amber-800' },
                        servicio_no_disponible: { etiqueta: 'SigWeb no disponible', texto: 'text-amber-800', punto: 'bg-amber-500', insignia: 'bg-amber-100 text-amber-800' },
                        pad_no_conectado: { etiqueta: 'Pad no conectado', texto: 'text-amber-800', punto: 'bg-amber-500', insignia: 'bg-amber-100 text-amber-800' },
                        listo: { etiqueta: 'Pad listo', texto: 'text-emerald-700', punto: 'bg-emerald-500', insignia: 'bg-emerald-100 text-emerald-800' },
                        capturando: { etiqueta: 'Capturando…', texto: 'text-inst-700', punto: 'bg-inst-500 animate-pulse', insignia: 'bg-blue-100 text-blue-800' },
                        error: { etiqueta: 'Error', texto: 'text-red-700', punto: 'bg-red-500', insignia: 'bg-red-100 text-red-800' },
                    };
                    return estados[this.padTopazEstado] ?? estados.sin_verificar;
                },

                verificarSigWeb() {
                    const topaz = this.padTopaz();
                    topaz?.verificar();
                    this.sincronizarEstadoTopaz();
                },

                iniciarCapturaTopaz() {
                    const topaz = this.padTopaz();
                    if (!topaz) {
                        this.sincronizarEstadoTopaz();
                        return;
                    }
                    this.descartarFirmaCapturada();
                    const canvas = document.getElementById('canvasTopazFirma');
                    canvas?.getContext('2d')?.clearRect(0, 0, canvas.width, canvas.height);
                    try {
                        topaz.iniciar(this.elementoSeleccionadoId, canvas);
                        this.sincronizarEstadoTopaz();
                    } catch (err) {
                        this.sincronizarEstadoTopaz(err.message);
                        this.mostrarToast('error', err.message);
                    }
                },

                limpiarCapturaTopaz() {
                    this.topaz?.limpiar(document.getElementById('canvasTopazFirma'));
                },

                // Devuelve true si había una captura activa que se canceló.
                cancelarCapturaTopaz(mensaje = null) {
                    if (!this.topaz?.cancelar()) return false;
                    const canvas = document.getElementById('canvasTopazFirma');
                    canvas?.getContext('2d')?.clearRect(0, 0, canvas.width, canvas.height);
                    this.sincronizarEstadoTopaz(mensaje);
                    return true;
                },

                async aceptarCapturaTopaz() {
                    const topaz = this.topaz;
                    if (!topaz || this.padTopazProcesando) return;
                    this.padTopazProcesando = true;
                    try {
                        const { blob, elementoId, puntos } = await topaz.aceptar();
                        // El operador pudo cambiar de elemento mientras SigWeb entregaba la imagen.
                        if (elementoId !== this.elementoSeleccionadoId) {
                            throw new Error('La firma se capturó para otro elemento y se descartó. Repita la captura.');
                        }
                        this.firmaCapturadaBlob = blob;
                        this.firmaCapturadaUrl = URL.createObjectURL(blob);
                        this.firmaTempPreviewUrl = this.firmaCapturadaUrl;
                        this.firmaCapturaElementoId = elementoId;
                        this.motivosCambio.firma = 'Firma capturada en pad Topaz SigLite LCD 1x5 mediante SigWeb.';
                        this.modalFirmaPreview = true;
                        this.sincronizarEstadoTopaz(`Firma obtenida del pad (${puntos} puntos). Revísela y acéptela; todavía no se ha guardado.`);
                    } catch (err) {
                        this.sincronizarEstadoTopaz(err.message);
                        this.mostrarToast('error', err.message);
                    } finally {
                        this.padTopazProcesando = false;
                    }
                },

                cerrarModalPrevisualizacion() {
                    this.cancelarCapturaTopaz();
                    this.firmaCapturaElementoId = null;
                    this.cancelarCanon();
                    this.descartarFotoCanon();
                    this.cancelarHuella();
                    this.descartarHuellaCapturada();
                    this.detenerCamara();
                    if (this.fotoCapturadaUrl) URL.revokeObjectURL(this.fotoCapturadaUrl);
                    if (this.firmaCapturadaUrl) URL.revokeObjectURL(this.firmaCapturadaUrl);
                    if (this.huellaPreviewUrl.pulgar_izquierdo) URL.revokeObjectURL(this.huellaPreviewUrl.pulgar_izquierdo);
                    if (this.huellaPreviewUrl.pulgar_derecho) URL.revokeObjectURL(this.huellaPreviewUrl.pulgar_derecho);
                    
                    this.fotoCapturadaBlob = null;
                    this.fotoCapturadaUrl = null;
                    this.firmaCapturadaBlob = null;
                    this.firmaCapturadaUrl = null;
                    if (this.firmaTempPreviewUrl && this.firmaTempPreviewUrl !== this.firmaCapturadaUrl) {
                        URL.revokeObjectURL(this.firmaTempPreviewUrl);
                    }
                    this.firmaTempPreviewUrl = null;
                    this.modalFirmaPreview = false;
                    this.huellaPreviewUrl = { pulgar_izquierdo: null, pulgar_derecho: null };

                    this.modalPrevisualizacion = false;
                    this.previewData = null;
                    this.errorModal = '';
                    this.exitoModal = '';
                    this.toastMensaje = '';
                    if (this.toastTimeout) clearTimeout(this.toastTimeout);
                    this.archivosSeleccionados = { foto: null, firma: null, pulgar_derecho: null, pulgar_izquierdo: null };
                    this.motivosCambio = { foto: '', firma: '', pulgar_derecho: '', pulgar_izquierdo: '' };
                },

                async seleccionarArchivoBiometrico(categoria, event) {
                    let file = event.target.files[0];
                    if (!file) return;

                    if (categoria === 'foto' && file.size > 2 * 1024 * 1024) {
                        try {
                            file = await this.redimensionarFoto(file);
                        } catch (err) {
                            this.errorModal = err.message || 'No fue posible preparar la fotografía.';
                            event.target.value = '';
                            return;
                        }
                    }

                    if (file.size > 2 * 1024 * 1024) {
                        this.errorModal = 'El archivo seleccionado excede el tamaño máximo permitido de 2 MB.';
                        this.mostrarToast('error', 'El archivo seleccionado excede el tamaño máximo permitido de 2 MB.');
                        event.target.value = '';
                        return;
                    }

                    this.archivosSeleccionados[categoria] = file;
                    this.errorModal = '';

                    // Si es huella, generar preview
                    if (categoria === 'pulgar_izquierdo' || categoria === 'pulgar_derecho') {
                        if (this.huellaPreviewUrl[categoria]) {
                            URL.revokeObjectURL(this.huellaPreviewUrl[categoria]);
                        }
                        this.huellaPreviewUrl[categoria] = URL.createObjectURL(file);
                        const labelDedo = categoria === 'pulgar_derecho' ? 'Pulgar Derecho' : 'Pulgar Izquierdo';
                        if (!this.motivosCambio[categoria]) {
                            this.motivosCambio[categoria] = `Captura dactilar oficial de ${labelDedo} importada desde lector Crossmatch.`;
                        }
                    } else if (categoria === 'foto' && !this.motivosCambio.foto) {
                        this.motivosCambio.foto = 'Carga de fotografía facial institucional.';
                    } else if (categoria === 'firma' && !this.motivosCambio.firma) {
                        this.motivosCambio.firma = 'Carga de firma digitalizada pericial.';
                    }

                    this.exitoModal = `Archivo cargado para ${categoria.replace('_', ' ')}.`;
                    this.mostrarToast('exito', `Archivo preparado para ${categoria.replace('_', ' ')}.`);
                },

                async guardarActualizacionBiometrico(categoria) {
                    const file = this.archivosSeleccionados[categoria];
                    if (!file) {
                        this.errorModal = 'Debe seleccionar un archivo para el slot biométrico.';
                        this.mostrarToast('error', 'Debe seleccionar un archivo para el slot biométrico.');
                        return;
                    }

                    const motivo = (this.motivosCambio[categoria] || '').trim();
                    if (motivo.length < 5) {
                        this.errorModal = 'Debe ingresar una justificación de al menos 5 caracteres para registrar el cambio.';
                        this.mostrarToast('error', 'Debe ingresar una justificación de al menos 5 caracteres.');
                        return;
                    }

                    this.guardandoBiometrico = true;
                    this.errorModal = '';
                    this.exitoModal = '';

                    // Una captura (pad, lienzo o Canon) solo se guarda en el elemento para el que se hizo; el servidor lo vuelve a comprobar.
                    const esFotoCanon = categoria === 'foto' && this.fotoCapturaTicket !== null && file === this.fotoCapturaArchivo;
                    const huellaPreparada = this.huellasPreparadas[categoria] ?? null;
                    const esHuellaAgente = huellaPreparada !== null && file === huellaPreparada.archivo;
                    const ticketAgente = esFotoCanon ? this.fotoCapturaTicket : (esHuellaAgente ? huellaPreparada.ticket : null);
                    const elementoCaptura = categoria === 'firma' ? this.firmaCapturaElementoId
                        : (esFotoCanon ? this.fotoCapturaElementoId : (esHuellaAgente ? huellaPreparada.elementoId : null));
                    if (elementoCaptura !== null && elementoCaptura !== this.elementoSeleccionadoId) {
                        if (categoria === 'firma') this.descartarFirmaCapturada(); else if (esHuellaAgente) this.descartarHuellaCapturada(categoria); else this.descartarFotoCanon();
                        this.errorModal = 'La captura se hizo para otro elemento y se descartó. Repítala con el elemento seleccionado.';
                        this.mostrarToast('error', this.errorModal);
                        return;
                    }

                    const formData = new FormData();
                    formData.append('tipo_biometrico', categoria);
                    formData.append('archivo', file);
                    formData.append('motivo_cambio', motivo);
                    if (elementoCaptura !== null) {
                        formData.append('elemento_captura_id', elementoCaptura);
                    }
                    if (ticketAgente !== null) {
                        formData.append('captura_ticket', ticketAgente);
                    }

                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

                    try {
                        const response = await fetch(`/impresion/elementos/${this.elementoSeleccionadoId}/actualizar-biometrico`, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken || '',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: formData
                        });

                        const data = await response.json();
                        if (!response.ok || !data.success) {
                            throw new Error(data.message || 'Error al actualizar el archivo biométrico.');
                        }

                        this.exitoModal = data.message;
                        this.mostrarToast('exito', data.message || 'Biométrico actualizado y sincronizado en caliente.');
                        this.cacheBuster = Date.now();

                        // Actualizar datos del slot en la previsualización en caliente
                        if (this.previewData && this.previewData.biometricos && this.previewData.biometricos[categoria]) {
                            this.previewData.biometricos[categoria].existe = true;
                            this.previewData.biometricos[categoria].url = data.stream_url;
                            this.previewData.biometricos[categoria].sha256 = data.sha256;
                            this.previewData.biometricos[categoria].version = data.version;
                            this.previewData.biometricos[categoria].actualizado = true;
                            this.miniaturas[categoria] = 'cargando';
                            this.previewData.semaforo.es_completo = data.es_completo;
                            this.previewData.semaforo.completos = data.total_biometricos;
                            this.previewData.elemento.estado_tramite = data.estado_tramite;
                            this.previewData.elemento.puede_imprimir = data.es_completo && !!this.previewData.elemento.folio;
                        }

                        // Limpiar archivo seleccionado y justificación
                        this.archivosSeleccionados[categoria] = null;
                        this.motivosCambio[categoria] = '';
                        if (categoria === 'firma') this.firmaCapturaElementoId = null;
                        if (esFotoCanon) {
                            this.descartarFotoCanon();
                            this.cancelarCanon('Fotografía guardada en el expediente; sesión de cámara cerrada.');
                        }
                        if (esHuellaAgente) {
                            // Solo la del pulgar guardado: la del otro pulgar sigue pendiente de guardar.
                            this.descartarHuellaCapturada(categoria);
                            this.cancelarHuella('Huella guardada en el expediente; lector liberado.');
                        }

                        // Sincronizar en la lista de elementos de la cola de impresión
                        const elemEnTabla = this.elementos.find(e => e.id === this.elementoSeleccionadoId);
                        if (elemEnTabla) {
                            elemEnTabla.biometria_valida = data.es_completo;
                            elemEnTabla.biometricos = { ...(elemEnTabla.biometricos || {}), [categoria]: { valido: false, motivo: null, actualizado: true } };
                            elemEnTabla.estado_tramite = data.estado_tramite;
                            elemEnTabla.puede_imprimir = data.es_completo && !!elemEnTabla.folio;
                        }
                    } catch (err) {
                        this.errorModal = err.message;
                        this.mostrarToast('error', err.message);
                    } finally {
                        this.guardandoBiometrico = false;
                    }
                }
            };
        }

        // Registro compatible de Alpine
        if (window.Alpine) {
            Alpine.data('printQueue', printQueue);
        } else {
            document.addEventListener('alpine:init', () => {
                Alpine.data('printQueue', printQueue);
            });
        }
    </script>
@endsection
