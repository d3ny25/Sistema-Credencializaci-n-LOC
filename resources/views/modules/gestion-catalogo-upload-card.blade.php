<x-card class="flex flex-col h-full" title="Catálogo organizacional" description="Catálogo oficial de claves, agrupamientos, municipios y adscripciones gobernado por LOG.">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        @if ($catalogo_version_activa)
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800">
                    Versión vigente: v{{ $catalogo_version_activa->version_numero }}
                </span>
                <span class="text-xs text-tx-soft">
                    ({{ number_format($catalogo_version_activa->total_registros) }} claves activas)
                </span>
            </div>
            <a href="{{ route('gestion.catalogo.vigente') }}" class="text-xs font-medium text-inst-700 hover:text-inst-900">
                Consultar catálogo vigente →
            </a>
        @else
            <x-status-badge label="Sin versión publicada" tone="neutral"/>
        @endif
    </div>

    @unless ($catalogo_import_schema_ready ?? true)
        <div class="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950" role="alert">
            La importación del catálogo no está habilitada en esta base: falta aplicar la migración de versiones (2026_10_26_000001).
        </div>
    @else
        @if (auth()->user()?->getAttribute('local_demo_access'))
            <div class="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="status">
                El modo demo es de solo lectura. Se requiere una cuenta administrativa real para cargar el catálogo.
            </div>
        @else
            <form id="catalogo-upload-form" class="flex flex-col flex-1 justify-between space-y-4" method="POST"
                action="{{ route('gestion.catalogo.importaciones.subir') }}"
                enctype="multipart/form-data" data-max-kb="{{ $max_upload_kilobytes }}">
                @csrf

                <div class="space-y-4">
                    <x-file-dropzone 
                        name="archivo_catalogo" 
                        id="archivo_catalogo"
                        label="Selecciona o arrastra el catálogo oficial (catalogo.xlsx)"
                        help="Libro Excel .xlsx o .xls · máximo {{ number_format($max_upload_kilobytes / 1024, 1) }} MB"
                        accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel"
                        :allowed-extensions="['xlsx', 'xls']"
                        :max-kilobytes="$max_upload_kilobytes"
                        button-text="Seleccionar archivo"
                        required />

                    <p id="catalogo-file-details" class="min-h-5 text-sm text-tx-soft" aria-live="polite">
                        Se validan las columnas A–J (Dirección, Coordinación, Región, Agrupamiento, Municipio, Clave, Zona y Batallón).
                    </p>
                </div>

                <div class="mt-auto flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
                    <p class="max-w-xl text-xs leading-5 text-tx-soft">
                        El archivo se analiza en una vista previa comparándolo con la versión vigente. La confirmación publica una versión histórica nueva de forma atómica sin sobrescribir las anteriores.
                    </p>
                    <button id="catalogo-upload-submit" type="submit"
                        class="inline-flex items-center justify-center gap-2 rounded-md bg-inst-700 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-inst-900 focus:outline-none focus:ring-2 focus:ring-inst-700 focus:ring-offset-1 disabled:cursor-wait disabled:opacity-70">
                        <svg id="catalogo-upload-spinner" class="hidden h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                        </svg>
                        <span id="catalogo-upload-submit-label">Analizar catálogo</span>
                    </button>
                </div>
            </form>

            <script>
            (() => {
                const form = document.getElementById('catalogo-upload-form');
                if (!form) return;
                form.addEventListener('submit', () => {
                    const btn = document.getElementById('catalogo-upload-submit');
                    const spinner = document.getElementById('catalogo-upload-spinner');
                    const label = document.getElementById('catalogo-upload-submit-label');
                    if (btn) btn.disabled = true;
                    if (spinner) spinner.classList.remove('hidden');
                    if (label) label.textContent = 'Analizando catálogo…';
                });
            })();
            </script>
        @endif
    @endunless
</x-card>
