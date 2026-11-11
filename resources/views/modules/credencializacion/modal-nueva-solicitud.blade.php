{{-- Modal para crear nueva solicitud de credencialización --}}
<div
    id="modal-nueva-solicitud"
    x-show="modalNuevaSolicitud"
    x-cloak
    @keydown.escape.window="modalNuevaSolicitud = false"
    class="fixed inset-0 z-50 flex min-h-screen items-center justify-center p-4 sm:p-6 overflow-y-auto"
    aria-labelledby="modal-title"
    role="dialog"
    aria-modal="true"
>
    <!-- Overlay de fondo con desenfoque suave -->
    <div
        x-show="modalNuevaSolicitud"
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity"
        @click="modalNuevaSolicitud = false"
        data-close-modal="nueva-solicitud"
        aria-hidden="true"
    ></div>

    <!-- Caja del diálogo modal centrada en viewport -->
    <div
        x-show="modalNuevaSolicitud"
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
        class="relative w-full max-w-lg max-h-[90vh] flex flex-col rounded-xl bg-white shadow-2xl ring-1 ring-slate-900/10 overflow-hidden z-10 my-auto"
        @click.stop
    >
        <form method="POST" action="{{ route('credencializacion.store') }}" class="flex flex-col max-h-[90vh] min-h-0">
            @csrf

            <!-- Cabecera fija del modal -->
            <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4 bg-slate-50/70 shrink-0">
                <div>
                    <h3 class="text-base font-semibold leading-6 text-inst-900" id="modal-title">
                        Registrar nueva solicitud de credencialización
                    </h3>
                    <p class="text-xs text-tx-soft mt-0.5">
                        Expediente institucional · Datos de registro
                    </p>
                </div>
                <button
                    type="button"
                    @click="modalNuevaSolicitud = false"
                    data-close-modal="nueva-solicitud"
                    class="rounded-lg p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition focus:outline-none focus:ring-2 focus:ring-inst-700"
                    aria-label="Cerrar modal"
                >
                    <x-icon name="x" class="h-5 w-5"/>
                </button>
            </div>

            <!-- Cuerpo del formulario con desplazamiento interno si excede la altura -->
            <div class="flex-1 overflow-y-auto px-6 py-5 space-y-4 min-h-0">
                <div class="rounded-lg bg-emerald-50/70 p-3.5 border border-emerald-200/70 text-xs">
                    <div class="flex items-center gap-2 font-semibold text-emerald-900">
                        <x-icon name="shield" class="h-4 w-4 text-emerald-600"/>
                        Número de tarjeta institucional
                    </div>
                    <p class="text-emerald-800/80 mt-1">
                        Se asignará de forma automática y consecutiva al registrar el expediente (generado por el servidor bajo la serie oficial permanente).
                    </p>
                </div>

                <div>
                    <label for="numero_oficio" class="block text-xs font-semibold text-tx">
                        Número de oficio <span class="text-estado-error">*</span>
                    </label>
                    <input
                        type="text"
                        id="numero_oficio"
                        name="numero_oficio"
                        required
                        maxlength="100"
                        placeholder="Ej. OF-SEG-2026-089"
                        class="mt-1 block w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inst-700 placeholder:text-slate-400"
                    >
                    <p class="text-[11px] text-tx-soft mt-1">Oficio institucional remitido por el área solicitante.</p>
                </div>

                <div>
                    <label for="fecha_recepcion" class="block text-xs font-semibold text-tx">
                        Fecha de recepción <span class="text-estado-error">*</span>
                    </label>
                    <input
                        type="date"
                        id="fecha_recepcion"
                        name="fecha_recepcion"
                        value="{{ date('Y-m-d') }}"
                        required
                        class="mt-1 block w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inst-700"
                    >
                </div>

                <div
                    class="relative"
                    x-data="{
                        search: '{{ addslashes(old('area_solicitante', '')) }}',
                        selectedId: '{{ old('catalogo_organizacional_id', '') }}',
                        selectedClave: '',
                        selectedNombre: '',
                        suggestions: [],
                        isOpen: false,
                        isLoading: false,
                        debounceTimer: null,

                        onInput() {
                            // Al modificar manualmente el texto, se desacopla inmediatamente
                            // de cualquier selección previa del catálogo institucional, evitando estados contradictorios.
                            this.selectedId = '';
                            this.selectedClave = '';
                            this.selectedNombre = '';

                            clearTimeout(this.debounceTimer);
                            const query = this.search.trim();

                            if (query.length < 2) {
                                this.suggestions = [];
                                this.isOpen = false;
                                this.isLoading = false;
                                return;
                            }

                            this.isLoading = true;
                            this.debounceTimer = setTimeout(() => {
                                fetch('{{ route('credencializacion.catalogo.buscar') }}?q=' + encodeURIComponent(query), {
                                    headers: {
                                        'Accept': 'application/json',
                                        'X-Requested-With': 'XMLHttpRequest'
                                    }
                                })
                                .then(res => res.json())
                                .then(data => {
                                    this.suggestions = data.data || [];
                                    this.isOpen = this.suggestions.length > 0;
                                })
                                .catch(() => {
                                    this.suggestions = [];
                                    this.isOpen = false;
                                })
                                .finally(() => {
                                    this.isLoading = false;
                                });
                            }, 250);
                        },

                        selectItem(item) {
                            this.selectedId = item.id;
                            this.selectedClave = item.clave;
                            this.selectedNombre = item.nombre;
                            this.search = item.nombre;
                            this.suggestions = [];
                            this.isOpen = false;
                        },

                        clearSelection() {
                            this.selectedId = '';
                            this.selectedClave = '';
                            this.selectedNombre = '';
                            this.search = '';
                            this.suggestions = [];
                            this.isOpen = false;
                        }
                    }"
                    @click.outside="isOpen = false"
                >
                    <div class="flex items-center justify-between">
                        <label for="area_solicitante" class="block text-xs font-semibold text-tx">
                            Área solicitante
                        </label>
                        <span x-show="selectedId" x-cloak class="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">
                            <x-icon name="check" class="h-3 w-3 text-emerald-600" />
                            Catálogo (<span x-text="selectedClave"></span>)
                            <button type="button" @click="clearSelection" class="ml-1 text-slate-400 hover:text-slate-600" title="Quitar selección">
                                <x-icon name="x" class="h-3 w-3" />
                            </button>
                        </span>
                    </div>

                    {{-- Identificador institucional para el servidor --}}
                    <input type="hidden" name="catalogo_organizacional_id" :value="selectedId">

                    <div class="relative mt-1">
                        <input
                            type="text"
                            id="area_solicitante"
                            name="area_solicitante"
                            x-model="search"
                            @input="onInput"
                            @focus="if (suggestions.length > 0) isOpen = true"
                            @keydown.escape="isOpen = false"
                            maxlength="180"
                            autocomplete="off"
                            placeholder="Buscar en catálogo institucional o capturar texto libre..."
                            class="block w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inst-700 placeholder:text-slate-400 pr-8"
                        >

                        {{-- Indicador de carga --}}
                        <div x-show="isLoading" x-cloak class="absolute inset-y-0 right-0 flex items-center pr-2.5 pointer-events-none">
                            <svg class="animate-spin h-4 w-4 text-inst-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </div>
                    </div>

                    {{-- Lista desplegable de sugerencias --}}
                    <div
                        x-show="isOpen && suggestions.length > 0"
                        x-cloak
                        x-transition:enter="ease-out duration-100"
                        x-transition:enter-start="opacity-0 scale-95"
                        x-transition:enter-end="opacity-100 scale-100"
                        x-transition:leave="ease-in duration-75"
                        x-transition:leave-start="opacity-100 scale-100"
                        x-transition:leave-end="opacity-0 scale-95"
                        class="absolute z-50 mt-1 w-full max-h-56 overflow-y-auto rounded-md bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none text-xs"
                    >
                        <template x-for="item in suggestions" :key="item.id">
                            <button
                                type="button"
                                @click="selectItem(item)"
                                class="w-full text-left px-3 py-2 hover:bg-inst-50 hover:text-inst-900 transition flex items-start justify-between gap-2 border-b border-slate-50 last:border-0"
                            >
                                <span class="font-medium text-slate-800" x-text="item.nombre"></span>
                                <span class="inline-block shrink-0 px-1.5 py-0.5 bg-slate-100 text-slate-700 font-mono text-[10px] rounded border border-slate-200" x-text="item.clave"></span>
                            </button>
                        </template>
                    </div>

                    <p class="text-[11px] text-tx-soft mt-1" x-show="!selectedId">
                        Escriba para buscar en el catálogo oficial o capture texto libre si no existe coincidencia.
                    </p>
                    <p class="text-[11px] text-emerald-700 mt-1" x-show="selectedId" x-cloak>
                        Registro institucional vinculado. Se preservará la clave institucional y la denominación congelada.
                    </p>
                </div>

                <div>
                    <label for="observaciones" class="block text-xs font-semibold text-tx">
                        Observaciones
                    </label>
                    <textarea
                        id="observaciones"
                        name="observaciones"
                        rows="2"
                        placeholder="Notas o antecedentes de la recepción..."
                        class="mt-1 block w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inst-700 placeholder:text-slate-400"
                    ></textarea>
                </div>
            </div>

            <!-- Barra de acciones fija en pie del modal -->
            <div class="border-t border-slate-100 bg-slate-50 px-6 py-3.5 flex flex-row-reverse items-center gap-2 shrink-0">
                <button
                    type="submit"
                    class="inline-flex justify-center rounded-md bg-inst-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-inst-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-inst-700 transition"
                >
                    Crear expediente
                </button>
                <button
                    type="button"
                    @click="modalNuevaSolicitud = false"
                    data-close-modal="nueva-solicitud"
                    class="inline-flex justify-center rounded-md border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-400 transition"
                >
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

