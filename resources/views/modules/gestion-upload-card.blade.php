<x-card class="flex flex-col h-full" title="Personal autorizado LOC" description="Actualiza el padrón de referencia después de revisar las filas propuestas.">
    <div class="mb-4 flex justify-end">
        <x-status-badge label="Análisis previo" tone="info"/>
    </div>

    @if (auth()->user()?->getAttribute('local_demo_access'))
        <div class="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="status">
            El modo demo local es de solo lectura. Para cargar y confirmar un archivo, inicia sesión con una cuenta administrativa real.
        </div>
    @else
        <form id="loc-upload-form" class="flex flex-col flex-1 justify-between space-y-4" method="POST"
            action="{{ route('gestion.importaciones.subir') }}"
            enctype="multipart/form-data" data-max-kb="{{ $max_upload_kilobytes }}">
            @csrf

            <div class="space-y-4">
                <x-file-dropzone 
                    name="archivo_loc" 
                    id="archivo_loc"
                    label="Selecciona o arrastra un archivo LOC"
                    help="Excel binario .xls · máximo {{ number_format($max_upload_kilobytes / 1024, 1) }} MB · no se admiten .xlsx"
                    accept=".xls,application/vnd.ms-excel"
                    :allowed-extensions="['xls']"
                    :max-kilobytes="$max_upload_kilobytes"
                    button-text="Seleccionar archivo"
                    required />

                <p id="loc-file-details" class="min-h-5 text-sm text-tx-soft" aria-live="polite">
                    Se validan las columnas oficiales de Personal LOC (CUIP, Nombre, RFC, CURP, Categoría, Estatus, Municipio y Adscripción).
                </p>
            </div>

            <div class="mt-auto flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
                <p class="max-w-lg text-xs leading-5 text-tx-soft">El servidor validará el formato y los encabezados. El análisis no modifica el padrón; la aplicación requiere una confirmación posterior.</p>
                <button id="loc-upload-submit" type="submit"
                    class="inline-flex items-center justify-center gap-2 rounded-md bg-inst-700 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-inst-900 focus:outline-none focus:ring-2 focus:ring-inst-700 focus:ring-offset-1 disabled:cursor-wait disabled:opacity-70">
                    <svg id="loc-upload-spinner" class="hidden h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                    </svg>
                    <span id="loc-upload-submit-label">Analizar archivo</span>
                </button>
            </div>
        </form>

        <script>
        (() => {
            const form = document.getElementById('loc-upload-form');
            if (!form) return;
            form.addEventListener('submit', () => {
                const btn = document.getElementById('loc-upload-submit');
                const spinner = document.getElementById('loc-upload-spinner');
                const label = document.getElementById('loc-upload-submit-label');
                if (btn) btn.disabled = true;
                if (spinner) spinner.classList.remove('hidden');
                if (label) label.textContent = 'Analizando archivo…';
            });
        })();
        </script>
    @endif
</x-card>
