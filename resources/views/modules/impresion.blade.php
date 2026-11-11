@extends('layouts.app')

@section('title', 'Impresión')
@section('heading', 'Impresión de credenciales')
@section('subheading', 'Cola de impresión y seguimiento biométrico')

@php
    try {
        $tarjetasDisponibles = \App\Models\SolicitudCredencial::query()
            ->orderByDesc('id')
            ->get(['id', 'numero_tarjeta', 'numero_oficio', 'area_solicitante', 'total_aptos']);
    } catch (\Throwable) {
        $tarjetasDisponibles = collect();
    }
@endphp

@section('content')
    <x-module-tabs :active="$section" :items="[
        ['key' => 'cola', 'label' => 'Cola de impresión', 'route' => 'impresion'],
        ['key' => 'biometricos', 'label' => 'Pendientes biométricos', 'route' => 'impresion.biometricos'],
    ]"/>

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-inst-100 bg-inst-50/70 p-4">
        <div>
            <p class="text-sm font-semibold text-inst-900">Diseño de credencial</p>
            <p class="mt-1 text-xs text-tx-soft">Editor de plantilla vertical para anverso y reverso; la edición se guarda como borrador con permisos.</p>
        </div>
        <div class="inline-flex gap-2">
            <a href="{{ route('impresion.plantilla', ['template_version' => 1]) }}" class="rounded-md border border-inst-300 bg-white px-3 py-2 text-xs font-semibold text-inst-900 hover:bg-inst-50 transition">Editar v1 Base</a>
            <a href="{{ route('impresion.plantilla', ['template_version' => 2]) }}" class="rounded-md bg-inst-700 px-3 py-2 text-xs font-semibold text-white hover:bg-inst-900 transition">Editar v2 Oficial Real</a>
        </div>
    </div>

    @if ($section === 'biometricos')
        <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach (['Pendientes biométricos', 'Fotografía pendiente', 'Firma pendiente', 'Huellas pendientes'] as $label)
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-card">
                    <p class="text-xs text-tx-soft">{{ $label }}</p>
                    <p class="mt-2 text-2xl font-semibold text-inst-900">—</p>
                    <p class="mt-1 text-xs text-tx-soft">Seguimiento pericial activo</p>
                </div>
            @endforeach
        </div>
        <x-card title="Pendientes biométricos" description="Los elementos con datos biométricos faltantes o ámbito no resuelto requieren solventación antes de su emisión.">
            <div class="p-8 text-center text-sm text-tx-soft">
                <p>Consulte la pestaña principal de la <strong>Cola de impresión</strong> para ejecutar la validación biométrica en tiempo real.</p>
                <div class="mt-4">
                    <a href="{{ route('impresion') }}" class="inline-flex items-center gap-2 rounded-md bg-inst-700 px-4 py-2 text-sm font-semibold text-white hover:bg-inst-800 transition">
                        <span>Ir a Cola de impresión</span>
                    </a>
                </div>
            </div>
        </x-card>
    @else
        {{-- Componente Reactivo Alpine.js de la Cola de Impresión --}}
        <div x-data="printQueue()" x-cloak class="space-y-5">
            <!-- 1. Tarjetas de Métricas / KPIs Reactivas -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-card">
                    <p class="text-xs font-semibold text-tx-soft uppercase tracking-wide">Trabajos en cola</p>
                    <p class="mt-2 text-2xl font-bold text-inst-900" x-text="metricas.trabajos_en_cola">0</p>
                    <p class="mt-1 text-[11px] text-tx-soft" x-text="selectedTarjeta ? 'Elementos aptos en tarjeta' : 'Sin tarjeta seleccionada'"></p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-card">
                    <p class="text-xs font-semibold text-tx-soft uppercase tracking-wide">Listos para imprimir</p>
                    <p class="mt-2 text-2xl font-bold text-emerald-700" x-text="metricas.listos_para_imprimir">0</p>
                    <p class="mt-1 text-[11px] text-tx-soft" x-text="selectedTarjeta ? 'Con biometría 4/4 y folio' : 'Sin tarjeta seleccionada'"></p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-card">
                    <p class="text-xs font-semibold text-tx-soft uppercase tracking-wide">Biometría pendiente</p>
                    <p class="mt-2 text-2xl font-bold text-amber-700" x-text="metricas.biometria_pendiente">0</p>
                    <p class="mt-1 text-[11px] text-tx-soft" x-text="selectedTarjeta ? 'Faltan archivos o ámbito' : 'Sin tarjeta seleccionada'"></p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-card">
                    <p class="text-xs font-semibold text-tx-soft uppercase tracking-wide">Impresos / Emitidos</p>
                    <p class="mt-2 text-2xl font-bold text-slate-700" x-text="metricas.impresos">0</p>
                    <p class="mt-1 text-[11px] text-tx-soft" x-text="selectedTarjeta ? 'Credenciales producidas' : 'Sin tarjeta seleccionada'"></p>
                </div>
            </div>

            <!-- 2. Banners de Alerta Reactivos -->
            <div x-show="mensajeExito" x-cloak class="rounded-lg border border-emerald-200 bg-emerald-50/90 p-4 text-sm text-emerald-900 flex items-start justify-between gap-3 shadow-2xs">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <span x-text="mensajeExito"></span>
                </div>
                <button type="button" @click="mensajeExito = ''" class="text-emerald-700 hover:text-emerald-900 text-lg leading-none font-bold">&times;</button>
            </div>

            <div x-show="mensajeError" x-cloak class="rounded-lg border border-rose-200 bg-rose-50/90 p-4 text-sm text-rose-900 flex items-start justify-between gap-3 shadow-2xs">
                <div class="flex items-center gap-2">
                    <svg class="h-5 w-5 text-rose-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span x-text="mensajeError"></span>
                </div>
                <button type="button" @click="mensajeError = ''" class="text-rose-700 hover:text-rose-900 text-lg leading-none font-bold">&times;</button>
            </div>

            <!-- 3. Contenedor de la Cola de Impresión -->
            <x-card title="Cola de impresión" description="Seleccione una tarjeta de trabajo para consultar sus elementos aptos y validar la biometría oficial.">
                <!-- Filtros y Selectores -->
                <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <!-- Selector de Tarjeta de Trabajo -->
                    <label class="text-xs font-semibold text-tx-soft">
                        Tarjeta de trabajo
                        <select data-tarjeta-trabajo-impresion
                                x-model="selectedTarjeta"
                                @change="cargarElementos()"
                                :disabled="isLoading"
                                class="mt-1 w-full rounded-md border-0 bg-white px-3 py-2 text-sm text-slate-800 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inst-600 disabled:bg-slate-100 disabled:cursor-not-allowed">
                            <option value="">-- Selecciona una tarjeta de trabajo --</option>
                            @foreach ($tarjetasDisponibles as $tarjeta)
                                <option value="{{ $tarjeta->id }}">
                                    {{ $tarjeta->numero_tarjeta }} · Oficio {{ $tarjeta->numero_oficio }} ({{ $tarjeta->total_aptos }} aptos)
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <!-- Búsqueda reactiva -->
                    <label class="text-xs font-semibold text-tx-soft">
                        Buscar en tarjeta
                        <input type="search"
                               x-model="search"
                               :disabled="!selectedTarjeta || isLoading"
                               placeholder="CUIP, nombre o folio"
                               class="mt-1 w-full rounded-md border-0 bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inst-600 disabled:bg-slate-100 disabled:cursor-not-allowed">
                    </label>

                    <!-- Filtro de Estado Biométrico -->
                    <label class="text-xs font-semibold text-tx-soft">
                        Estado biométrico
                        <select x-model="filtroEstado"
                                :disabled="!selectedTarjeta || isLoading"
                                class="mt-1 w-full rounded-md border-0 bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inst-600 disabled:bg-slate-100 disabled:cursor-not-allowed">
                            <option value="todos">Todos los estados</option>
                            <option value="listo">Listos para imprimir (4/4)</option>
                            <option value="pendiente">Pendientes de biometría</option>
                        </select>
                    </label>

                    <!-- Filtro de Ámbito Institucional -->
                    <label class="text-xs font-semibold text-tx-soft">
                        Ámbito institucional
                        <select x-model="filtroAmbito"
                                :disabled="!selectedTarjeta || isLoading"
                                class="mt-1 w-full rounded-md border-0 bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inst-600 disabled:bg-slate-100 disabled:cursor-not-allowed">
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
                                Mostrando <strong class="text-slate-800" x-text="elementosFiltrados.length"></strong> de <strong class="text-slate-800" x-text="elementos.length"></strong> elementos aptos
                            </span>
                        </template>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Botón Refrescar -->
                        <button type="button"
                                @click="cargarElementos()"
                                :disabled="isLoading || !selectedTarjeta"
                                class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-2xs hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 transition">
                            <svg class="h-4 w-4 text-slate-500" :class="isLoading ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                            <span>Refrescar</span>
                        </button>

                        <!-- Botón Validar Biometría -->
                        <button type="button"
                                @click="validarBiometria()"
                                x-bind:disabled="isLoading || !selectedTarjeta"
                                class="inline-flex items-center gap-2 rounded-md bg-inst-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-inst-800 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500 transition">
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
                <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
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

                    <!-- Tabla Reactiva con x-for -->
                    <table x-show="selectedTarjeta && elementos.length > 0" class="min-w-full divide-y divide-slate-200 text-left text-xs">
                        <thead class="bg-slate-50/95 font-bold uppercase tracking-wider text-slate-600 text-[10px]">
                            <tr>
                                <th scope="col" class="w-[50px] px-3 py-3 text-center"># / Fila</th>
                                <th scope="col" class="w-[160px] px-3 py-3">CUIP</th>
                                <th scope="col" class="px-3 py-3">Nombre</th>
                                <th scope="col" class="w-[140px] px-3 py-3">Tarjeta</th>
                                <th scope="col" class="w-[110px] px-3 py-3">Ámbito</th>
                                <th scope="col" class="w-[140px] px-3 py-3">Folio</th>
                                <th scope="col" class="w-[200px] px-3 py-3">Semáforo Biometría</th>
                                <th scope="col" class="w-[130px] px-3 py-3 text-center">Estado</th>
                                <th scope="col" class="w-[110px] px-3 py-3 text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <template x-for="(elem, index) in elementosFiltrados" :key="elem.id">
                                <tr class="hover:bg-inst-50/20 transition-colors">
                                    <!-- 1. # / Fila -->
                                    <td class="px-3 py-2 text-center whitespace-nowrap">
                                        <span class="font-mono font-bold text-slate-800" x-text="'#' + (index + 1)"></span>
                                        <div class="font-mono text-[9px] text-slate-400" x-text="elem.numero_fila ? 'F. ' + elem.numero_fila : ''"></div>
                                    </td>

                                    <!-- 2. CUIP -->
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        <div class="inline-flex items-center gap-1 font-mono text-[11px] font-bold text-inst-900">
                                            <span x-text="elem.cuip"></span>
                                            <button type="button"
                                                    @click="navigator.clipboard.writeText(elem.cuip)"
                                                    class="text-slate-400 hover:text-inst-700 transition"
                                                    title="Copiar CUIP">
                                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    </td>

                                    <!-- 3. Nombre -->
                                    <td class="px-3 py-2">
                                        <div class="font-bold text-slate-900 leading-tight truncate max-w-xs" :title="elem.nombre" x-text="elem.nombre"></div>
                                        <div class="text-[10px] text-slate-500 mt-0.5 truncate max-w-xs" x-text="[elem.categoria, elem.municipio].filter(Boolean).join(' · ')"></div>
                                    </td>

                                    <!-- 4. Tarjeta -->
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        <span class="font-mono text-xs text-slate-700" x-text="elem.numero_tarjeta || numeroTarjeta || '—'"></span>
                                    </td>

                                    <!-- 5. Ámbito -->
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        <template x-if="elem.ambito_biometrico === 'ESTATALES'">
                                            <span class="inline-flex items-center rounded bg-sky-50 px-2 py-0.5 text-[10px] font-semibold text-sky-800 border border-sky-200">
                                                Estatales
                                            </span>
                                        </template>
                                        <template x-if="elem.ambito_biometrico === 'PREVENCION'">
                                            <span class="inline-flex items-center rounded bg-purple-50 px-2 py-0.5 text-[10px] font-semibold text-purple-800 border border-purple-200">
                                                Prevención
                                            </span>
                                        </template>
                                        <template x-if="elem.ambito_biometrico === 'NO_RESUELTO' || !elem.ambito_biometrico">
                                            <span class="inline-flex items-center rounded bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-600 border border-slate-200">
                                                No Resuelto
                                            </span>
                                        </template>
                                    </td>

                                    <!-- 6. Folio Institucional -->
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        <span class="font-mono text-xs font-bold text-inst-900 bg-inst-50/90 px-2 py-0.5 rounded border border-inst-200"
                                              x-text="elem.folio || '—'"></span>
                                    </td>

                                    <!-- 6. Semáforo Biométrico -->
                                    <td class="px-3 py-2">
                                        <!-- Completa 4/4 -->
                                        <template x-if="elem.biometria_valida === true">
                                            <div class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-semibold text-emerald-800 border border-emerald-200">
                                                <svg class="h-3.5 w-3.5 text-emerald-600" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                                </svg>
                                                <span>Completa 4/4</span>
                                            </div>
                                        </template>

                                        <!-- Incompleta o Faltantes -->
                                        <template x-if="elem.biometria_valida === false">
                                            <div class="space-y-1">
                                                <div class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-[10.5px] font-semibold text-rose-800 border border-rose-200">
                                                    <svg class="h-3 w-3 text-rose-600 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                                                    </svg>
                                                    <span>Incompleta</span>
                                                </div>
                                                <template x-if="elem.faltantes && elem.faltantes.length > 0">
                                                    <div class="space-y-0.5">
                                                        <template x-for="falta in elem.faltantes" :key="falta">
                                                            <div class="text-[9.5px] text-rose-700 truncate max-w-[190px]" :title="falta" x-text="'• ' + falta"></div>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>

                                        <!-- Sin validar aún -->
                                        <template x-if="elem.biometria_valida === undefined || elem.biometria_valida === null">
                                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-[10px] font-medium text-slate-600 border border-slate-200">
                                                Sin validar
                                            </span>
                                        </template>
                                    </td>

                                    <!-- 8. Estado Trámite -->
                                    <td class="px-3 py-2 text-center whitespace-nowrap">
                                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10.5px] font-semibold"
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
                                            <span x-text="elem.estado_tramite"></span>
                                        </span>
                                    </td>

                                    <!-- 8. Acciones -->
                                    <td class="px-3 py-2 text-center whitespace-nowrap">
                                        <button type="button"
                                                @click="abrirModalPrevisualizacion(elem.id)"
                                                class="inline-flex items-center gap-1 rounded bg-inst-700 px-2.5 py-1 text-xs font-semibold text-white shadow-2xs hover:bg-inst-800 transition"
                                                title="Previsualizar credencial y gestionar datos biométricos">
                                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                            <span>Vista previa</span>
                                        </button>
                                    </td>
                                </tr>
                            </template>

                            <!-- Estado Vacío: Sin coincidencias tras filtros -->
                            <template x-if="elementos.length > 0 && elementosFiltrados.length === 0">
                                <tr>
                                    <td colspan="9" class="px-6 py-8 text-center text-xs text-tx-soft">
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
                 @keydown.escape.window="cerrarModalPrevisualizacion()"
                 class="fixed inset-0 z-50 flex justify-center p-2 sm:p-3 bg-slate-900/60 backdrop-blur-sm overflow-y-auto">
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
                         class="absolute top-4 right-4 z-50 max-w-sm rounded-lg bg-white p-3 shadow-xl border"
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
                            <button type="button" @click="toastMensaje = ''" class="text-slate-400 hover:text-slate-600 text-sm font-bold ml-1">&times;</button>
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
                                <h3 class="text-sm sm:text-base font-bold text-slate-900">
                                    Vista Previa de Credencial y Control Biométrico
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
                            <template x-if="previewData">
                                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                                      :class="previewData.semaforo.es_completo ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-amber-50 text-amber-800 border border-amber-200'">
                                    <span class="h-1.5 w-1.5 rounded-full" :class="previewData.semaforo.es_completo ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                                    <span x-text="'Biometría ' + previewData.semaforo.completos + '/4'"></span>
                                </span>
                            </template>
                            <button type="button"
                                    @click="cerrarModalPrevisualizacion()"
                                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-200 hover:text-slate-700 transition"
                                    title="Cerrar ventana">
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
                            <div class="lg:col-span-6 xl:col-span-7 bg-slate-100/80 p-3 sm:p-4 flex flex-col justify-between overflow-y-auto h-full">
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

                                        <!-- Fondo Institucional Reactivo -->
                                        <img :src="caraCredencial === 'front' ? previewData.template.front_bg : previewData.template.rear_bg"
                                             class="absolute inset-0 w-full h-full object-fill pointer-events-none transition-opacity duration-200"
                                             alt="Fondo institucional de credencial">

                                        <!-- Elementos de la Cara Activa -->
                                        <template x-for="el in (caraCredencial === 'front' ? previewData.template.config.faces.front.elements : previewData.template.config.faces.rear.elements)"
                                                  :key="caraCredencial + '_' + el.id">
                                            <div x-show="el.visible"
                                                 class="absolute"
                                                 :style="styleCardElement(el)">
                                                
                                                <!-- Caso 1 y 2: Texto Fijo o Campo Dinámico -->
                                                <template x-if="el.kind === 'static' || el.kind === 'dynamic'">
                                                    <span :style="styleSpanText(el)"
                                                          :class="{
                                                              'font-mono font-bold tracking-tight text-red-600': el.field === 'folio' && !el.font_family,
                                                              'font-mono font-bold tracking-wider': el.field === 'cuip' && !el.font_family,
                                                              'font-extrabold': el.id === 'nombre' && !el.font_family,
                                                              'font-bold': el.id === 'categoria' && !el.font_family
                                                          }"
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
                            <div class="lg:col-span-6 xl:col-span-5 bg-white p-3 sm:p-4 overflow-y-auto flex flex-col justify-between h-full space-y-3">
                                
                                <div class="space-y-3.5">
                                    <!-- Monitor de Periféricos de Estación -->
                                    <div class="rounded-lg border border-slate-200 bg-slate-50/90 p-2.5 text-xs space-y-1.5 shadow-xs">
                                        <div class="flex items-center justify-between border-b border-slate-200/80 pb-1">
                                            <span class="font-bold uppercase tracking-wider text-slate-700 text-[10.5px] flex items-center gap-1.5">
                                                <svg class="h-3.5 w-3.5 text-inst-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
                                                </svg>
                                                Periféricos de Estación
                                            </span>
                                            <span class="font-mono text-[10px] text-tx-soft" x-text="previewData.elemento.folio || 'Sin folio asignado'"></span>
                                        </div>
                                        <div class="grid grid-cols-3 gap-1.5 text-[10px]">
                                            <div class="flex items-center gap-1.5 rounded bg-white px-2 py-1 border border-slate-200 shadow-xs">
                                                <span class="h-2 w-2 rounded-full shrink-0" :class="camaraActiva ? 'bg-emerald-500 animate-pulse' : 'bg-slate-400'"></span>
                                                <span class="truncate text-slate-700 font-medium" :title="camaraActiva ? 'Cámara activa' : 'Cámara lista'">📷 Cámara</span>
                                            </div>
                                            <div class="flex items-center gap-1.5 rounded bg-white px-2 py-1 border border-slate-200 shadow-xs">
                                                <span class="h-2 w-2 rounded-full shrink-0" :class="padTopazEstado === 'conectado' ? 'bg-emerald-500' : (padTopazEstado === 'capturando' ? 'bg-blue-500 animate-pulse' : 'bg-amber-400')"></span>
                                                <span class="truncate text-slate-700 font-medium" :title="padTopazEstado === 'conectado' ? 'Topaz conectado' : 'Lienzo / Topaz'">✒️ Pad / Lienzo</span>
                                            </div>
                                            <div class="flex items-center gap-1.5 rounded bg-white px-2 py-1 border border-slate-200 shadow-xs">
                                                <span class="h-2 w-2 rounded-full shrink-0 bg-blue-500"></span>
                                                <span class="truncate text-slate-700 font-medium" title="Lector Crossmatch / BMP / PNG">👆 Crossmatch</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Matriz 2x2 de Micro-Tarjetas de Biometría (Slots de Expediente) -->
                                    <div>
                                        <div class="flex items-center justify-between mb-1.5">
                                            <span class="text-xs font-bold uppercase tracking-wider text-slate-700">Expediente Biométrico</span>
                                            <span class="text-[10.5px] font-semibold text-slate-500" x-text="'Completitud: ' + previewData.semaforo.completos + '/4'"></span>
                                        </div>
                                        <div class="grid grid-cols-2 gap-2">
                                            <!-- Slot 1: Foto Facial -->
                                            <div @click="cambiarSlot('foto')"
                                                 class="cursor-pointer rounded-lg p-2.5 transition-all text-left relative overflow-hidden"
                                                 :class="slotActivo === 'foto' ? 'bg-inst-50/70 border-2 border-inst-700 shadow-xs' : 'bg-white border border-slate-200 hover:border-slate-300 hover:bg-slate-50/60'">
                                                <div class="flex items-center justify-between">
                                                    <span class="text-[11px] font-bold text-slate-900 flex items-center gap-1.5">
                                                        📷 Foto Facial
                                                    </span>
                                                    <template x-if="previewData.biometricos.foto.existe">
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                            <span class="h-1 w-1 rounded-full bg-emerald-500"></span>
                                                            Vigente
                                                        </span>
                                                    </template>
                                                    <template x-if="!previewData.biometricos.foto.existe">
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                                            <span class="h-1 w-1 rounded-full bg-amber-500"></span>
                                                            Falta
                                                        </span>
                                                    </template>
                                                </div>
                                                <div class="mt-2 flex items-center justify-between text-[10px] text-slate-500 border-t border-slate-100 pt-1.5">
                                                    <span class="font-semibold text-slate-600" x-text="'v' + (previewData.biometricos.foto.version || 1)"></span>
                                                    <div class="flex items-center gap-1 font-mono text-[9px]" :title="previewData.biometricos.foto.sha256 || 'Sin registro'">
                                                        <span x-text="previewData.biometricos.foto.sha256 ? previewData.biometricos.foto.sha256.substring(0, 8) + '...' : '—'"></span>
                                                        <template x-if="previewData.biometricos.foto.sha256">
                                                            <button type="button" @click.stop="copiarHash(previewData.biometricos.foto.sha256)" class="text-slate-400 hover:text-inst-700" title="Copiar SHA-256">
                                                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                                            </button>
                                                        </template>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Slot 2: Firma Digital -->
                                            <div @click="cambiarSlot('firma')"
                                                 class="cursor-pointer rounded-lg p-2.5 transition-all text-left relative overflow-hidden"
                                                 :class="slotActivo === 'firma' ? 'bg-inst-50/70 border-2 border-inst-700 shadow-xs' : 'bg-white border border-slate-200 hover:border-slate-300 hover:bg-slate-50/60'">
                                                <div class="flex items-center justify-between">
                                                    <span class="text-[11px] font-bold text-slate-900 flex items-center gap-1.5">
                                                        ✒️ Firma Digital
                                                    </span>
                                                    <template x-if="previewData.biometricos.firma.existe">
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                            <span class="h-1 w-1 rounded-full bg-emerald-500"></span>
                                                            Vigente
                                                        </span>
                                                    </template>
                                                    <template x-if="!previewData.biometricos.firma.existe">
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                                            <span class="h-1 w-1 rounded-full bg-amber-500"></span>
                                                            Falta
                                                        </span>
                                                    </template>
                                                </div>
                                                <div class="mt-2 flex items-center justify-between text-[10px] text-slate-500 border-t border-slate-100 pt-1.5">
                                                    <span class="font-semibold text-slate-600" x-text="'v' + (previewData.biometricos.firma.version || 1)"></span>
                                                    <div class="flex items-center gap-1 font-mono text-[9px]" :title="previewData.biometricos.firma.sha256 || 'Sin registro'">
                                                        <span x-text="previewData.biometricos.firma.sha256 ? previewData.biometricos.firma.sha256.substring(0, 8) + '...' : '—'"></span>
                                                        <template x-if="previewData.biometricos.firma.sha256">
                                                            <button type="button" @click.stop="copiarHash(previewData.biometricos.firma.sha256)" class="text-slate-400 hover:text-inst-700" title="Copiar SHA-256">
                                                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                                            </button>
                                                        </template>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Slot 3: Pulgar Izquierdo -->
                                            <div @click="cambiarSlot('pulgar_izquierdo')"
                                                 class="cursor-pointer rounded-lg p-2.5 transition-all text-left relative overflow-hidden"
                                                 :class="slotActivo === 'pulgar_izquierdo' ? 'bg-inst-50/70 border-2 border-inst-700 shadow-xs' : 'bg-white border border-slate-200 hover:border-slate-300 hover:bg-slate-50/60'">
                                                <div class="flex items-center justify-between">
                                                    <span class="text-[11px] font-bold text-slate-900 flex items-center gap-1.5">
                                                        👆 Pulgar Izquierdo
                                                    </span>
                                                    <template x-if="previewData.biometricos.pulgar_izquierdo.existe">
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                            <span class="h-1 w-1 rounded-full bg-emerald-500"></span>
                                                            Vigente
                                                        </span>
                                                    </template>
                                                    <template x-if="!previewData.biometricos.pulgar_izquierdo.existe">
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                                            <span class="h-1 w-1 rounded-full bg-amber-500"></span>
                                                            Falta
                                                        </span>
                                                    </template>
                                                </div>
                                                <div class="mt-2 flex items-center justify-between text-[10px] text-slate-500 border-t border-slate-100 pt-1.5">
                                                    <span class="font-semibold text-slate-600" x-text="'v' + (previewData.biometricos.pulgar_izquierdo.version || 1)"></span>
                                                    <div class="flex items-center gap-1 font-mono text-[9px]" :title="previewData.biometricos.pulgar_izquierdo.sha256 || 'Sin registro'">
                                                        <span x-text="previewData.biometricos.pulgar_izquierdo.sha256 ? previewData.biometricos.pulgar_izquierdo.sha256.substring(0, 8) + '...' : '—'"></span>
                                                        <template x-if="previewData.biometricos.pulgar_izquierdo.sha256">
                                                            <button type="button" @click.stop="copiarHash(previewData.biometricos.pulgar_izquierdo.sha256)" class="text-slate-400 hover:text-inst-700" title="Copiar SHA-256">
                                                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                                            </button>
                                                        </template>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Slot 4: Pulgar Derecho -->
                                            <div @click="cambiarSlot('pulgar_derecho')"
                                                 class="cursor-pointer rounded-lg p-2.5 transition-all text-left relative overflow-hidden"
                                                 :class="slotActivo === 'pulgar_derecho' ? 'bg-inst-50/70 border-2 border-inst-700 shadow-xs' : 'bg-white border border-slate-200 hover:border-slate-300 hover:bg-slate-50/60'">
                                                <div class="flex items-center justify-between">
                                                    <span class="text-[11px] font-bold text-slate-900 flex items-center gap-1.5">
                                                        👆 Pulgar Derecho
                                                    </span>
                                                    <template x-if="previewData.biometricos.pulgar_derecho.existe">
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                            <span class="h-1 w-1 rounded-full bg-emerald-500"></span>
                                                            Vigente
                                                        </span>
                                                    </template>
                                                    <template x-if="!previewData.biometricos.pulgar_derecho.existe">
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[9px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                                            <span class="h-1 w-1 rounded-full bg-amber-500"></span>
                                                            Falta
                                                        </span>
                                                    </template>
                                                </div>
                                                <div class="mt-2 flex items-center justify-between text-[10px] text-slate-500 border-t border-slate-100 pt-1.5">
                                                    <span class="font-semibold text-slate-600" x-text="'v' + (previewData.biometricos.pulgar_derecho.version || 1)"></span>
                                                    <div class="flex items-center gap-1 font-mono text-[9px]" :title="previewData.biometricos.pulgar_derecho.sha256 || 'Sin registro'">
                                                        <span x-text="previewData.biometricos.pulgar_derecho.sha256 ? previewData.biometricos.pulgar_derecho.sha256.substring(0, 8) + '...' : '—'"></span>
                                                        <template x-if="previewData.biometricos.pulgar_derecho.sha256">
                                                            <button type="button" @click.stop="copiarHash(previewData.biometricos.pulgar_derecho.sha256)" class="text-slate-400 hover:text-inst-700" title="Copiar SHA-256">
                                                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                                            </button>
                                                        </template>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- ============================================== -->
                                    <!-- SECCIÓN ACTIVA: FOTOGRAFÍA FACIAL             -->
                                    <!-- ============================================== -->
                                    <div x-show="slotActivo === 'foto'" class="space-y-3">
                                        <!-- Sub-pestañas de Fotografía -->
                                        <div class="flex items-center justify-between border-b border-slate-200 pb-1.5">
                                            <span class="text-xs font-semibold text-slate-800">1. Fotografía Facial</span>
                                            <div class="inline-flex rounded-md bg-slate-100 p-0.5 text-[10.5px]">
                                                <button type="button"
                                                        @click="modoCapturaFoto = 'camara'"
                                                        :class="modoCapturaFoto === 'camara' ? 'bg-white text-inst-900 font-bold shadow-2xs' : 'text-slate-600 hover:text-slate-900'"
                                                        class="rounded px-2.5 py-0.5 transition">Cámara Web / USB</button>
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
                                                        <p class="text-xs font-semibold text-slate-200">Cámara desconectada</p>
                                                        <p class="text-[10px] text-slate-400 mt-0.5">Active la cámara para captura pericial en vivo.</p>
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
                                                        <button type="button" @click="detenerCamara()" class="rounded bg-black/60 px-2 py-1 text-[10px] text-white hover:bg-black/80 transition">Detener</button>
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
                                                        <span class="text-[10px] text-emerald-400 font-semibold">✓ Fotograma 3:4</span>
                                                        <button type="button" @click="descartarFotoCapturada()" class="rounded bg-slate-700 px-2 py-0.5 text-[10px] text-white hover:bg-slate-600 transition">Reintentar</button>
                                                    </div>
                                                </div>
                                            </div>

                                            <div x-show="camaraError" class="text-[11px] text-rose-600 font-medium text-center" x-text="camaraError"></div>
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
                                                    <span class="mt-0.5 block text-[10px] text-tx-soft">Compatible con carpeta de descarga de EOS Utility y cámaras USB (JPG/PNG).</span>
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
                                            <div class="inline-flex rounded-md bg-slate-100 p-0.5 text-[10.5px]">
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
                                                <span class="text-[10px] font-mono text-slate-500">Canal alfa (PNG)</span>
                                            </div>
                                            <div class="relative bg-slate-50 border border-slate-200 rounded p-2 flex items-center justify-center min-h-[95px]">
                                                <img :src="firmaTempPreviewUrl" class="max-h-20 max-w-full object-contain" alt="Vista previa de firma capturada">
                                                <div class="absolute bottom-1 right-2 text-[9px] text-slate-400 italic">Verificación pericial</div>
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
                                            <button type="button" @click="firmaTempPreviewUrl = (firmaCapturadaUrl || (archivosSeleccionados.firma ? URL.createObjectURL(archivosSeleccionados.firma) : null)); modalFirmaPreview = true;" class="text-xs font-bold text-inst-700 hover:underline">Ver trazo</button>
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
                                                
                                                <div class="absolute bottom-1.5 left-2.5 pointer-events-none text-[9.5px] text-slate-400 italic">
                                                    Firme con lápiz óptico, pantalla táctil o ratón
                                                </div>

                                                <div class="absolute top-1.5 right-2 flex items-center gap-1">
                                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-mono text-slate-600" x-text="firmaTrazos > 0 ? firmaTrazos + ' trazos' : 'En espera'"></span>
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
                                                <span class="rounded px-2 py-0.5 text-[9.5px] font-bold"
                                                      :class="padTopazEstado === 'conectado' ? 'bg-emerald-100 text-emerald-800' : (padTopazEstado === 'capturando' ? 'bg-blue-100 text-blue-800' : 'bg-amber-100 text-amber-800')"
                                                      x-text="padTopazEstado === 'conectado' ? 'SigWeb OK' : (padTopazEstado === 'capturando' ? 'Comunicando...' : 'Pendiente')"></span>
                                            </div>

                                            <p class="text-[11px] text-tx-soft leading-relaxed">
                                                Interactúa directamente con el servicio local de Topaz <code class="font-mono text-[10px] bg-slate-200/80 px-1 py-0.5 rounded">SigWeb</code> ejecutado en <code class="font-mono text-[10px] bg-slate-200/80 px-1 py-0.5 rounded">localhost:47289</code>.
                                            </p>

                                            <div x-show="padTopazMensaje" class="rounded bg-white p-2 border border-slate-200 text-[10.5px] text-slate-700 font-mono" x-text="padTopazMensaje"></div>

                                            <div class="flex items-center gap-2">
                                                <button type="button"
                                                        @click="verificarSigWeb()"
                                                        class="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 transition">
                                                    Probar Servicio SigWeb
                                                </button>
                                                <button type="button"
                                                        @click="activarPadTopaz()"
                                                        class="rounded bg-inst-700 px-3 py-1 text-xs font-semibold text-white shadow-2xs hover:bg-inst-800 transition">
                                                    Activar Pad Topaz
                                                </button>
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
                                                    <span class="mt-0.5 block text-[10px] text-tx-soft">Formatos admitidos: PNG transparente o JPG (máx. 2MB).</span>
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
                                            <span class="rounded bg-blue-50 text-blue-700 px-2 py-0.5 text-[10px] font-bold">Lector Crossmatch / BMP / PNG</span>
                                        </div>

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
                                                <span class="mt-0.5 block text-[10px] text-tx-soft">Admite exportaciones BMP y PNG generadas por Crossmatch Verifier 300.</span>
                                            </label>
                                        </div>

                                        <div x-show="huellaPreviewUrl[slotActivo]" class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white p-2">
                                            <div class="h-16 w-12 bg-slate-100 rounded overflow-hidden border border-slate-300 shrink-0">
                                                <img :src="huellaPreviewUrl[slotActivo]" class="w-full h-full object-contain" alt="Vista previa dactilar">
                                            </div>
                                            <div class="min-w-0 text-xs">
                                                <p class="font-bold text-slate-800 truncate" x-text="archivosSeleccionados[slotActivo]?.name"></p>
                                                <p class="text-[10px] text-tx-soft" x-text="archivosSeleccionados[slotActivo] ? Math.round(archivosSeleccionados[slotActivo].size / 1024) + ' KB' : ''"></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- ============================================== -->
                                <!-- CONSOLA DE SINCRONIZACIÓN Y JUSTIFICACIÓN     -->
                                <!-- ============================================== -->
                                <div class="pt-3 border-t border-slate-200 space-y-2.5 shrink-0">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-bold uppercase tracking-wider text-slate-700">Justificación Forense (Obligatoria)</span>
                                        <template x-if="archivosSeleccionados[slotActivo]">
                                            <span class="text-[10px] text-emerald-700 font-semibold flex items-center gap-1">
                                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                                Archivo preparado
                                            </span>
                                        </template>
                                        <template x-if="!archivosSeleccionados[slotActivo]">
                                            <span class="text-[10px] text-amber-700 font-medium">Sin archivo seleccionado</span>
                                        </template>
                                    </div>

                                    <!-- Sugerencias de Justificación Rápida -->
                                    <div class="flex flex-wrap gap-1">
                                        <button type="button"
                                                @click="motivosCambio[slotActivo] = 'Captura biométrica inicial en estación de credencialización.'"
                                                class="rounded bg-slate-100 hover:bg-slate-200 px-2 py-0.5 text-[9.5px] text-slate-700 transition">
                                            + Captura inicial
                                        </button>
                                        <button type="button"
                                                @click="motivosCambio[slotActivo] = 'Renovación oficial de credencial pericial LOC 139.'"
                                                class="rounded bg-slate-100 hover:bg-slate-200 px-2 py-0.5 text-[9.5px] text-slate-700 transition">
                                            + Renovación oficial
                                        </button>
                                        <button type="button"
                                                @click="motivosCambio[slotActivo] = 'Actualización por requerimiento de nitidez y calidad pericial.'"
                                                class="rounded bg-slate-100 hover:bg-slate-200 px-2 py-0.5 text-[9.5px] text-slate-700 transition">
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
                                        <span class="absolute right-2 top-1/2 -translate-y-1/2 text-[9px] font-mono pointer-events-none"
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

    <!-- Script de Alpine.js para la Cola de Impresión -->
    <script>
        function printQueue() {
            return {
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
                modoCapturaFirma: 'lienzo',
                
                // Módulo de Cámara Web / USB
                camaraActiva: false,
                camaraIniciando: false,
                camaraStream: null,
                camaraError: '',
                fotoCapturadaBlob: null,
                fotoCapturadaUrl: null,

                // Módulo Pad Topaz / Lienzo
                padTopazEstado: 'no_probado',
                padTopazMensaje: '',
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
                                    return {
                                        ...elem,
                                        ambito_biometrico: det.ambito || elem.ambito_biometrico,
                                        biometria_valida: det.biometria_valida,
                                        folio: det.folio,
                                        estado_tramite: det.estado_tramite,
                                        faltantes: det.faltantes || [],
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

                async abrirModalPrevisualizacion(elementoId, templateVer = null) {
                    this.elementoSeleccionadoId = elementoId;
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

                    const canvas = document.createElement('canvas');
                    const targetW = 600;
                    const targetH = 800; // ratio 3:4 pericial
                    canvas.width = targetW;
                    canvas.height = targetH;
                    const ctx = canvas.getContext('2d');

                    const vw = video.videoWidth || 1280;
                    const vh = video.videoHeight || 720;
                    
                    let cropW = vh * (3 / 4);
                    let cropH = vh;
                    if (cropW > vw) {
                        cropW = vw;
                        cropH = vw * (4 / 3);
                    }
                    const cropX = (vw - cropW) / 2;
                    const cropY = (vh - cropH) / 2;

                    ctx.drawImage(video, cropX, cropY, cropW, cropH, 0, 0, targetW, targetH);

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
                    return {
                        left: el.x + '%',
                        top: el.y + '%',
                        width: el.width + '%',
                        height: el.height + '%',
                        zIndex: (el.kind === 'image' ? 10 : 20),
                        border: el.border || 'none',
                        boxSizing: 'border-box'
                    };
                },

                styleSpanText(el) {
                    const rotation = el.rotation || 0;
                    const isRotated = (rotation !== 0);
                    const width = parseFloat(el.width) || 1;
                    const height = parseFloat(el.height) || 1;
                    const spanWidth = isRotated ? ((height / width) * 100).toFixed(2) + '%' : '100%';
                    const spanHeight = isRotated ? ((width / height) * 100).toFixed(2) + '%' : '100%';
                    const spanTransform = 'translate(-50%, -50%)' + (isRotated ? ` rotate(${rotation}deg)` : '');

                    let fs = el.font_size ? (el.font_size * 1.05) : 7;
                    if (fs < 5.5) fs = 5.5;

                    const isCode = (el.field === 'folio' || el.field === 'cuip');
                    const defaultFont = isCode ? 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace' : 'Arial, Inter, sans-serif';

                    return {
                        position: 'absolute',
                        top: '50%',
                        left: '50%',
                        width: spanWidth,
                        height: spanHeight,
                        transform: spanTransform,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: el.align === 'left' ? 'flex-start' : (el.align === 'right' ? 'flex-end' : 'center'),
                        textAlign: el.align || 'center',
                        fontSize: fs.toFixed(1) + 'px',
                        fontFamily: el.font_family || defaultFont,
                        fontWeight: el.weight || 400,
                        color: el.color || '#111827',
                        whiteSpace: (isCode && !el.font_family) ? 'nowrap' : 'pre-line',
                        overflowWrap: (isCode && !el.font_family) ? 'normal' : 'anywhere',
                        wordBreak: (isCode && !el.font_family) ? 'normal' : 'break-word',
                        lineHeight: el.line_height || 1.1,
                        pointerEvents: 'none'
                    };
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
                    this.modalFirmaPreview = false;
                    this.limpiarFirmaCanvas();
                },

                async verificarSigWeb() {
                    this.padTopazEstado = 'capturando';
                    this.padTopazMensaje = 'Sondeando servicio local SigWeb en localhost:47289...';
                    const controller = new AbortController();
                    const timeoutId = setTimeout(() => controller.abort(), 2000);

                    try {
                        const response = await fetch('http://localhost:47289/SigWeb/TabletState', {
                            signal: controller.signal
                        }).catch(() => null);

                        clearTimeout(timeoutId);

                        if (response && response.ok) {
                            this.padTopazEstado = 'conectado';
                            this.padTopazMensaje = 'Servicio Topaz SigWeb detectado y disponible en esta estación.';
                        } else {
                            this.padTopazEstado = 'no_detectado';
                            this.padTopazMensaje = 'Servicio Topaz SigWeb no detectado en localhost:47289. Utilice el lienzo táctil integrado.';
                        }
                    } catch (_) {
                        clearTimeout(timeoutId);
                        this.padTopazEstado = 'no_detectado';
                        this.padTopazMensaje = 'Servicio Topaz SigWeb no detectado. Utilice el lienzo táctil integrado.';
                    }
                },

                async activarPadTopaz() {
                    this.padTopazEstado = 'capturando';
                    this.padTopazMensaje = 'Iniciando captura en pad Topaz SigLite...';
                    try {
                        const res = await fetch('http://localhost:47289/SigWeb/TabletState/1', { method: 'POST' }).catch(() => null);
                        if (!res || !res.ok) {
                            throw new Error('El servicio SigWeb no respondió en localhost:47289. Por favor inicie el servicio o use el lienzo táctil.');
                        }

                        this.padTopazMensaje = 'Pad activo. Firme en el dispositivo Topaz...';
                        await new Promise(r => setTimeout(r, 1500));
                        const imgRes = await fetch('http://localhost:47289/SigWeb/SignatureImage').catch(() => null);
                        if (imgRes && imgRes.ok) {
                            const b64 = await imgRes.text();
                            if (b64 && b64.length > 50) {
                                const byteCharacters = atob(b64.replace(/^data:image\/\w+;base64,/, ''));
                                const byteNumbers = new Array(byteCharacters.length);
                                for (let i = 0; i < byteCharacters.length; i++) {
                                    byteNumbers[i] = byteCharacters.charCodeAt(i);
                                }
                                const byteArray = new Uint8Array(byteNumbers);
                                const blob = new Blob([byteArray], { type: 'image/png' });
                                
                                this.firmaCapturadaBlob = blob;
                                if (this.firmaCapturadaUrl) {
                                    URL.revokeObjectURL(this.firmaCapturadaUrl);
                                }
                                this.firmaCapturadaUrl = URL.createObjectURL(blob);
                                this.firmaTempPreviewUrl = this.firmaCapturadaUrl;
                                this.modalFirmaPreview = true;
                                this.padTopazEstado = 'conectado';
                                this.padTopazMensaje = 'Firma recuperada desde pad Topaz. Valide la previsualización.';
                                this.exitoModal = 'Firma Topaz obtenida exitosamente.';
                                this.mostrarToast('exito', 'Firma obtenida desde pad Topaz. Valide la previsualización.');
                                return;
                            }
                        }
                        throw new Error('No se detectó firma en el pad Topaz.');
                    } catch (err) {
                        this.padTopazEstado = 'no_detectado';
                        this.padTopazMensaje = err.message;
                        this.errorModal = err.message;
                    }
                },

                cerrarModalPrevisualizacion() {
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

                seleccionarArchivoBiometrico(categoria, event) {
                    const file = event.target.files[0];
                    if (!file) return;

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

                    const formData = new FormData();
                    formData.append('tipo_biometrico', categoria);
                    formData.append('archivo', file);
                    formData.append('motivo_cambio', motivo);

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
                            this.previewData.semaforo.es_completo = data.es_completo;
                            this.previewData.semaforo.completos = data.total_biometricos;
                            this.previewData.elemento.estado_tramite = data.estado_tramite;
                            this.previewData.elemento.puede_imprimir = data.es_completo && !!this.previewData.elemento.folio;
                        }

                        // Limpiar archivo seleccionado y justificación
                        this.archivosSeleccionados[categoria] = null;
                        this.motivosCambio[categoria] = '';

                        // Sincronizar en la lista de elementos de la cola de impresión
                        const elemEnTabla = this.elementos.find(e => e.id === this.elementoSeleccionadoId);
                        if (elemEnTabla) {
                            elemEnTabla.biometria_valida = data.es_completo;
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
