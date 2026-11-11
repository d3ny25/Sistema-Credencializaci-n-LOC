{{-- Tabla de elementos importados y operaciones de prefiltro --}}
@php
    $hasActiveFilters = !empty($filters) && count(array_filter($filters)) > 0;
    $totalCoincidentes = $elementos instanceof \Illuminate\Pagination\LengthAwarePaginator ? $elementos->total() : $elementos->count();
    $cardTitle = $hasActiveFilters
        ? "Elementos del expediente ({$totalCoincidentes} coincidentes de {$solicitud->total_elementos} registrados)"
        : "Elementos del expediente ({$solicitud->total_elementos} registrados)";
    $cardDesc = $hasActiveFilters
        ? "Visualizando resultados filtrados del expediente en servidor. Total: {$totalCoincidentes} elementos."
        : "Listado íntegro de filas cargadas desde el archivo Excel original, conservando registros con CUIP vacío o duplicado.";
    $showAdvanced = !empty($filters['agrupamiento']) || !empty($filters['clave']) || !empty($filters['arma_corta']) || !empty($filters['arma_larga']);
    $urlBase = route('credencializacion.detalle', $solicitud->id);
@endphp

<div class="mt-4"
     x-data="solicitudElementosManager({
         url: '{{ $urlBase }}',
         solicitudId: {{ $solicitud->id }},
         showAdvanced: {{ $showAdvanced ? 'true' : 'false' }}
     })">
    <x-card :title="$cardTitle" :description="$cardDesc">
        {{-- Banner de error accesible en caso de fallo AJAX / 422 / 500 --}}
        <div x-show="errorMessage"
             x-cloak
             class="mb-3 rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800 flex items-center justify-between shadow-2xs">
            <div class="flex items-center gap-2">
                <svg class="h-4 w-4 text-rose-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <span x-text="errorMessage"></span>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" @click="submitForm()" class="font-semibold text-rose-700 hover:text-rose-900 underline text-[11px]">
                    Reintentar
                </button>
                <button type="button" @click="errorMessage = null" class="text-rose-400 hover:text-rose-600 font-bold ml-1 text-sm leading-none">
                    ×
                </button>
            </div>
        </div>

        {{-- Barra de herramientas de filtros y búsqueda (Fase 2E) --}}
        <div class="mb-4 rounded-lg border border-slate-200/90 bg-slate-50/70 p-3 sm:p-3.5 shadow-2xs">
            <form x-ref="filterForm"
                  @submit.prevent="submitForm"
                  method="GET"
                  action="{{ $urlBase }}"
                  class="space-y-2.5">
                {{-- Fila 1: Búsqueda q, Prefiltro y Motivo --}}
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-12">
                    {{-- Búsqueda 'q' (CUIP, Persona, Matrícula, Adscripción) --}}
                    <div class="sm:col-span-2 xl:col-span-5">
                        <label for="filter-q" class="block text-[10px] sm:text-[11px] font-semibold uppercase tracking-wider text-tx-soft mb-1">
                            Búsqueda general
                        </label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5 text-slate-400">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                            </span>
                            <input type="text"
                                   id="filter-q"
                                   name="q"
                                   value="{{ request('q') }}"
                                   placeholder="Buscar por CUIP, nombre, matrícula, adscripción..."
                                   maxlength="100"
                                   class="h-8 sm:h-9 w-full rounded-md border border-slate-300 bg-white pl-8 pr-7 text-xs text-slate-800 shadow-2xs focus:border-inst-500 focus:outline-none focus:ring-2 focus:ring-inst-500/20 placeholder:text-slate-400">
                            @if(request('q'))
                                @php
                                    $clearQUrl = route('credencializacion.detalle', array_merge(['solicitud' => $solicitud->id], request()->except('q', 'page')));
                                @endphp
                                <a href="{{ $clearQUrl }}"
                                   data-chip-url="{{ $clearQUrl }}"
                                   class="absolute inset-y-0 right-0 flex items-center pr-2 text-slate-400 hover:text-slate-600 transition"
                                   title="Borrar término de búsqueda">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </a>
                            @endif
                        </div>
                    </div>

                    {{-- Filtro Prefiltro --}}
                    <div class="xl:col-span-3">
                        <label for="filter-prefiltro" class="block text-[10px] sm:text-[11px] font-semibold uppercase tracking-wider text-tx-soft mb-1">
                            Resultado prefiltro
                        </label>
                        <select id="filter-prefiltro"
                                name="prefiltro"
                                class="h-8 sm:h-9 w-full rounded-md border border-slate-300 bg-white px-2.5 text-xs text-slate-800 shadow-2xs focus:border-inst-500 focus:outline-none focus:ring-2 focus:ring-inst-500/20">
                            <option value="">Todos los resultados</option>
                            <option value="aptos" {{ strtolower((string)request('prefiltro')) === 'aptos' ? 'selected' : '' }}>
                                Aptos ({{ $solicitud->total_aptos }})
                            </option>
                            <option value="descartados" {{ strtolower((string)request('prefiltro')) === 'descartados' ? 'selected' : '' }}>
                                Descartados ({{ $solicitud->total_descartados }})
                            </option>
                            <option value="sin_evaluar" {{ strtolower((string)request('prefiltro')) === 'sin_evaluar' ? 'selected' : '' }}>
                                Sin evaluar ({{ max(0, (int)$solicitud->total_elementos - ((int)$solicitud->total_aptos + (int)$solicitud->total_descartados)) }})
                            </option>
                        </select>
                    </div>

                    {{-- Filtro Motivo de Descarte (14 sustantivos + 1 técnico + histórico) --}}
                    <div class="xl:col-span-4">
                        <label for="filter-motivo" class="block text-[10px] sm:text-[11px] font-semibold uppercase tracking-wider text-tx-soft mb-1">
                            Motivo de descarte
                        </label>
                        <select id="filter-motivo"
                                name="motivo"
                                class="h-8 sm:h-9 w-full rounded-md border border-slate-300 bg-white px-2.5 text-xs text-slate-800 shadow-2xs focus:border-inst-500 focus:outline-none focus:ring-2 focus:ring-inst-500/20 truncate">
                            <option value="">Todos los motivos</option>
                            <option value="historico" {{ request('motivo') === 'historico' ? 'selected' : '' }}>
                                [Histórico] Sin clasificar o residual
                            </option>
                            @foreach (\App\Support\MotivoDescarte::catalog() as $cod => $meta)
                                <option value="{{ $cod }}" {{ request('motivo') === $cod ? 'selected' : '' }}>
                                    {{ $meta['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Fila 2: Filtros avanzados colapsables (Agrupamiento y Armamento) --}}
                <div x-show="showAdvanced" x-cloak class="pt-2.5 border-t border-slate-200/90">
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        {{-- Agrupamiento Estado --}}
                        <div>
                            <label for="filter-agrupamiento" class="block text-[10px] sm:text-[11px] font-semibold uppercase tracking-wider text-tx-soft mb-1">
                                Agrupamiento institucional
                            </label>
                            <select id="filter-agrupamiento"
                                    name="agrupamiento"
                                    class="h-8 sm:h-9 w-full rounded-md border border-slate-300 bg-white px-2.5 text-xs text-slate-800 shadow-2xs focus:border-inst-500 focus:outline-none focus:ring-2 focus:ring-inst-500/20">
                                <option value="">Todos los estados</option>
                                <option value="verificado" {{ strtolower((string)request('agrupamiento')) === 'verificado' ? 'selected' : '' }}>Clave verificada</option>
                                <option value="sin_clave" {{ strtolower((string)request('agrupamiento')) === 'sin_clave' ? 'selected' : '' }}>Texto origen sin clave</option>
                                <option value="discrepante" {{ strtolower((string)request('agrupamiento')) === 'discrepante' ? 'selected' : '' }}>Discrepante (Excel vs Plantilla)</option>
                                <option value="ambiguo" {{ strtolower((string)request('agrupamiento')) === 'ambiguo' ? 'selected' : '' }}>Ambiguo (Múltiples claves)</option>
                                <option value="sin_informacion" {{ strtolower((string)request('agrupamiento')) === 'sin_informacion' ? 'selected' : '' }}>Sin información</option>
                            </select>
                        </div>

                        {{-- Clave Específica de Catálogo --}}
                        <div>
                            <label for="filter-clave" class="block text-[10px] sm:text-[11px] font-semibold uppercase tracking-wider text-tx-soft mb-1">
                                Clave institucional
                            </label>
                            <input type="text"
                                   id="filter-clave"
                                   name="clave"
                                   value="{{ request('clave') }}"
                                   placeholder="Ej. A302, A1, B210..."
                                   maxlength="50"
                                   class="h-8 sm:h-9 w-full rounded-md border border-slate-300 bg-white px-3 text-xs text-slate-800 shadow-2xs focus:border-inst-500 focus:outline-none focus:ring-2 focus:ring-inst-500/20 placeholder:text-slate-400 font-mono uppercase">
                        </div>

                        {{-- Arma Corta --}}
                        <div>
                            <label for="filter-arma-corta" class="block text-[10px] sm:text-[11px] font-semibold uppercase tracking-wider text-tx-soft mb-1">
                                Arma corta
                            </label>
                            <select id="filter-arma-corta"
                                    name="arma_corta"
                                    class="h-8 sm:h-9 w-full rounded-md border border-slate-300 bg-white px-2.5 text-xs text-slate-800 shadow-2xs focus:border-inst-500 focus:outline-none focus:ring-2 focus:ring-inst-500/20">
                                <option value="">Todas las condiciones</option>
                                <option value="con_arma" {{ request('arma_corta') === 'con_arma' || request('arma_corta') === 'solicitada' ? 'selected' : '' }}>Con arma solicitada</option>
                                <option value="sin_arma" {{ request('arma_corta') === 'sin_arma' ? 'selected' : '' }}>Sin arma solicitada</option>
                                <option value="vinculada" {{ request('arma_corta') === 'vinculada' ? 'selected' : '' }}>Arma vinculada oficial</option>
                                <option value="no_vinculada" {{ request('arma_corta') === 'no_vinculada' ? 'selected' : '' }}>Solicitada no vinculada</option>
                            </select>
                        </div>

                        {{-- Arma Larga --}}
                        <div>
                            <label for="filter-arma-larga" class="block text-[10px] sm:text-[11px] font-semibold uppercase tracking-wider text-tx-soft mb-1">
                                Arma larga
                            </label>
                            <select id="filter-arma-larga"
                                    name="arma_larga"
                                    class="h-8 sm:h-9 w-full rounded-md border border-slate-300 bg-white px-2.5 text-xs text-slate-800 shadow-2xs focus:border-inst-500 focus:outline-none focus:ring-2 focus:ring-inst-500/20">
                                <option value="">Todas las condiciones</option>
                                <option value="con_arma" {{ request('arma_larga') === 'con_arma' || request('arma_larga') === 'solicitada' ? 'selected' : '' }}>Con arma solicitada</option>
                                <option value="sin_arma" {{ request('arma_larga') === 'sin_arma' ? 'selected' : '' }}>Sin arma solicitada</option>
                                <option value="vinculada" {{ request('arma_larga') === 'vinculada' ? 'selected' : '' }}>Arma vinculada oficial</option>
                                <option value="no_vinculada" {{ request('arma_larga') === 'no_vinculada' ? 'selected' : '' }}>Solicitada no vinculada</option>
                            </select>
                        </div>
                    </div>
                </div>

                {{-- Fila 3: Botones de Acción y Toggle Avanzado --}}
                <div class="flex flex-wrap items-center justify-between gap-2 pt-0.5">
                    <div class="flex items-center gap-2">
                        <button type="submit"
                                :disabled="isLoading"
                                class="inline-flex items-center gap-1.5 rounded-md bg-inst-700 px-3 py-1.5 text-xs font-semibold text-white shadow-2xs hover:bg-inst-800 disabled:opacity-50 disabled:cursor-not-allowed transition">
                            <svg x-show="!isLoading" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <svg x-show="isLoading" x-cloak class="animate-spin h-3.5 w-3.5 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            <span x-text="isLoading ? 'Filtrando...' : 'Filtrar'">Filtrar</span>
                        </button>

                        <button type="button"
                                @click="showAdvanced = !showAdvanced"
                                class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 shadow-2xs hover:bg-slate-50 transition">
                            <span x-text="showAdvanced ? 'Menos filtros' : 'Más filtros (Agrupamiento / Armas)'"></span>
                            <svg class="h-3.5 w-3.5 text-slate-400 transition-transform duration-200" :class="showAdvanced ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                        </button>

                        {{-- Indicador sutil de carga asíncrona --}}
                        <div x-show="isLoading" x-cloak class="hidden sm:inline-flex items-center gap-1.5 text-xs text-inst-700 font-medium pl-1">
                            <span class="h-2 w-2 rounded-full bg-inst-600 animate-pulse"></span>
                            <span>Actualizando tabla...</span>
                        </div>
                    </div>

                    <div>
                        <a href="{{ $urlBase }}"
                           data-clear-filters="{{ $urlBase }}"
                           class="inline-flex items-center gap-1 text-xs font-semibold text-rose-700 hover:text-rose-900 transition">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            Limpiar filtros
                        </a>
                    </div>
                </div>

                {{-- Chips de Filtros Activos (Contenedor Reactivo) --}}
                <div x-ref="chipsContainer" @click="handleContainerClick($event)">
                    @include('modules.credencializacion.partials.filtros-chips')
                </div>
            </form>
        </div>

        {{-- Contenedor de la Tabla Integrada con Paginación (Contenedor Reactivo AJAX) --}}
        <div x-ref="tableContainer"
             @click="handleContainerClick($event)"
             class="relative transition-opacity duration-150"
             :class="isLoading ? 'opacity-50 pointer-events-none' : ''">
            @include('modules.credencializacion.partials.tabla-elementos')
        </div>
    </x-card>

    {{-- Operaciones del Expediente --}}
    <div class="mt-4">
        <x-card title="Operaciones del expediente" description="Evaluación cruzada y continuidad hacia la cola de impresión.">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold text-inst-900">Validación de prefiltro y emisión de credenciales</p>
                    <p class="text-xs text-tx-soft mt-0.5">El prefiltro cruzará personal, vigencias y armamento oficial. Solo los elementos aptos podrán enviarse a impresión.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2.5">
                    @if ($solicitud->total_elementos > 0)
                        <form method="POST" action="{{ route('credencializacion.prefiltro', $solicitud->id) }}">
                            @csrf
                            <button type="submit" title="Motor de prefiltro oficial institucional" class="inline-flex items-center gap-2 rounded-md bg-inst-700 px-4 py-2 text-xs font-bold text-white shadow-2xs hover:bg-inst-800 transition">
                                <x-icon name="shield" class="h-4 w-4"/>
                                {{ $solicitud->fecha_prefiltro ? 'Reejecutar Prefiltro' : 'Ejecutar Prefiltro' }}
                            </button>
                        </form>
                    @else
                        <button type="button" disabled title="Debe cargar elementos de Excel para habilitar el prefiltro." class="rounded-md bg-slate-200 px-4 py-2 text-xs font-medium text-slate-500 cursor-not-allowed">
                            Ejecutar Prefiltro
                        </button>
                    @endif

                    <button type="button" disabled title="El envío a impresión será implementado en la Fase 2.4." class="rounded-md border border-slate-200 bg-slate-50 px-4 py-2 text-xs font-medium text-slate-400 cursor-not-allowed">
                        Enviar elementos aptos a impresión · Fase 2.4
                    </button>
                </div>
            </div>
        </x-card>
    </div>
</div>

{{-- Registro del Componente Reactivo en Alpine.js --}}
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('solicitudElementosManager', (config) => ({
            url: config.url,
            solicitudId: config.solicitudId,
            showAdvanced: config.showAdvanced || false,
            isLoading: false,
            errorMessage: null,
            abortController: null,

            init() {
                // Sincronización del historial del navegador (Atrás / Adelante)
                window.addEventListener('popstate', (event) => {
                    this.fetchData(window.location.href, false);
                });
            },

            // Envío del formulario asíncrono
            submitForm(event) {
                if (event) event.preventDefault();
                const form = this.$refs.filterForm;
                if (!form) return;

                const formData = new FormData(form);
                const params = new URLSearchParams();

                for (const [key, value] of formData.entries()) {
                    const trimmed = typeof value === 'string' ? value.trim() : value;
                    if (trimmed !== '' && trimmed !== 'todos' && trimmed !== 'Todos') {
                        params.append(key, trimmed);
                    }
                }

                const queryString = params.toString();
                const targetUrl = queryString ? `${this.url}?${queryString}` : this.url;
                this.fetchData(targetUrl, true);
            },

            // Petición asíncrona hacia el servidor con Fetch
            async fetchData(fetchUrl, updateHistory = true) {
                // Cancelar solicitud en vuelo anterior para evitar colisiones (race conditions)
                if (this.abortController) {
                    this.abortController.abort();
                }
                this.abortController = new AbortController();

                this.isLoading = true;
                this.errorMessage = null;

                try {
                    const response = await fetch(fetchUrl, {
                        method: 'GET',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                        },
                        signal: this.abortController.signal,
                    });

                    if (!response.ok) {
                        if (response.status === 422) {
                            const errorData = await response.json();
                            const firstError = errorData.errors ? Object.values(errorData.errors)[0][0] : 'Parámetros de búsqueda inválidos.';
                            throw new Error(firstError);
                        }
                        throw new Error(`Error en el servidor (${response.status}). Intente nuevamente.`);
                    }

                    const data = await response.json();

                    // Actualización atómica de encabezado de tarjeta
                    const h2 = this.$el.querySelector('header h2');
                    if (h2 && data.title) {
                        h2.textContent = data.title;
                    }
                    const desc = this.$el.querySelector('header p');
                    if (desc && data.description) {
                        desc.textContent = data.description;
                    }

                    // Actualización atómica de chips
                    if (this.$refs.chipsContainer && data.chips_html !== undefined) {
                        this.$refs.chipsContainer.innerHTML = data.chips_html;
                    }

                    // Actualización atómica de la tabla
                    if (this.$refs.tableContainer && data.table_html !== undefined) {
                        this.$refs.tableContainer.innerHTML = data.table_html;

                        // Reinicializar árbol reactivo de Alpine en nodos dinámicos inyectados
                        this.$nextTick(() => {
                            if (window.Alpine && this.$refs.tableContainer) {
                                Alpine.initTree(this.$refs.tableContainer);
                            }
                        });
                    }

                    // Actualización del historial del navegador
                    if (updateHistory) {
                        window.history.pushState({ url: fetchUrl }, '', fetchUrl);
                    }

                    // Sincronizar inputs del formulario con la URL actual
                    this.syncFormWithUrl(fetchUrl);

                } catch (err) {
                    if (err.name === 'AbortError') {
                        // Solicitud cancelada intencionalmente por nueva acción del usuario
                        return;
                    }
                    console.error('Error al actualizar elementos:', err);
                    this.errorMessage = err.message || 'No fue posible actualizar los resultados. Compruebe su conexión.';
                } finally {
                    this.isLoading = false;
                }
            },

            // Delegación de eventos para paginación y chips sin recargar página
            handleContainerClick(event) {
                // 1. Paginador: interceptar clics en enlaces .pagination a
                const paginationLink = event.target.closest('.pagination a, [rel="prev"], [rel="next"]');
                if (paginationLink && paginationLink.href) {
                    event.preventDefault();
                    this.fetchData(paginationLink.href, true);
                    return;
                }

                // 2. Chips de filtro: remover filtro individual
                const chipRemoveLink = event.target.closest('a[data-chip-url]');
                if (chipRemoveLink && chipRemoveLink.dataset.chipUrl) {
                    event.preventDefault();
                    this.fetchData(chipRemoveLink.dataset.chipUrl, true);
                    return;
                }

                // 3. Limpiar filtros
                const clearLink = event.target.closest('a[data-clear-filters]');
                if (clearLink && clearLink.dataset.clearFilters) {
                    event.preventDefault();
                    this.fetchData(clearLink.dataset.clearFilters, true);
                    return;
                }
            },

            // Sincronizar campos del formulario tras cambio de URL
            syncFormWithUrl(currentUrl) {
                try {
                    const urlObj = new URL(currentUrl, window.location.origin);
                    const params = urlObj.searchParams;
                    const form = this.$refs.filterForm;
                    if (!form) return;

                    ['q', 'prefiltro', 'motivo', 'agrupamiento', 'clave', 'arma_corta', 'arma_larga'].forEach(name => {
                        const input = form.elements[name];
                        if (input) {
                            input.value = params.get(name) || '';
                        }
                    });

                    if (params.get('agrupamiento') || params.get('clave') || params.get('arma_corta') || params.get('arma_larga')) {
                        this.showAdvanced = true;
                    }
                } catch (e) {
                    // Ignorar errores de parsing
                }
            }
        }));
    });
</script>
