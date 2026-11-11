<x-card class="flex flex-col h-full" title="LOC · Armamento" description="La matrícula identifica cada arma; ID_ARMAMENTO se conserva únicamente como referencia externa.">
    <div class="mb-4 flex justify-end">
        <x-status-badge label="Análisis previo" tone="info"/>
    </div>

    @unless ($weapons_import_schema_ready)
        <div class="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950" role="alert">
            La importación no está habilitada: falta revisar y aplicar la migración incremental 2026_10_22_000001 en un entorno autorizado.
        </div>
    @else
        @if (auth()->user()?->getAttribute('local_demo_access'))
            <div class="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="status">
                El modo demo es de solo lectura. Se requiere una cuenta administrativa real para analizar el archivo.
            </div>
        @else
            <form id="weapons-upload-form" class="flex flex-col flex-1 justify-between space-y-4" method="POST"
                action="{{ route('gestion.armamento.importaciones.subir') }}"
                enctype="multipart/form-data" data-max-kb="{{ $max_upload_kilobytes }}">
                @csrf
                <input type="hidden" name="reanalysis_request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">

                <div class="space-y-4">
                    <x-file-dropzone 
                        name="archivo_armas" 
                        id="archivo_armas"
                        label="Selecciona o arrastra el inventario de armamento"
                        help="Excel binario .xls · máximo {{ number_format($max_upload_kilobytes / 1024, 1) }} MB · no se admiten .xlsx"
                        accept=".xls,application/vnd.ms-excel"
                        :allowed-extensions="['xls']"
                        :max-kilobytes="$max_upload_kilobytes"
                        button-text="Seleccionar archivo"
                        required />

                    <p id="weapons-file-details" class="min-h-5 text-sm text-tx-soft" aria-live="polite">
                        Las 66 columnas originales se conservan en staging y en el registro nuevo al confirmar.
                    </p>

                    @error('reanalyze')
                        <p class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $message }}</p>
                    @enderror

                    <label class="flex items-start gap-2 text-xs leading-5 text-tx-soft">
                        <input name="reanalyze" type="checkbox" value="1" @checked(old('reanalyze')) class="mt-1 rounded border-slate-300 text-inst-700 focus:ring-inst-700">
                        <span>Crear un lote independiente para reanalizar este mismo archivo si ya existe un lote previo.</span>
                    </label>
                </div>

                <div class="mt-auto flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
                    <p class="max-w-xl text-xs leading-5 text-tx-soft">El análisis no cambia el inventario. Los registros existentes nunca se sobrescriben; matrículas vacías, duplicadas o con datos distintos quedan bloqueadas para revisión.</p>
                    <button id="weapons-upload-submit" type="submit"
                        class="inline-flex items-center justify-center gap-2 rounded-md bg-inst-700 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-inst-900 focus:outline-none focus:ring-2 focus:ring-inst-700 focus:ring-offset-1 disabled:cursor-wait disabled:opacity-70">
                        <svg id="weapons-upload-spinner" class="hidden h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                        </svg>
                        <span id="weapons-upload-submit-label">Analizar armamento</span>
                    </button>
                </div>
            </form>

            <script>
            (() => {
                const form = document.getElementById('weapons-upload-form');
                if (!form) return;
                form.addEventListener('submit', () => {
                    const btn = document.getElementById('weapons-upload-submit');
                    const spinner = document.getElementById('weapons-upload-spinner');
                    const label = document.getElementById('weapons-upload-submit-label');
                    if (btn) btn.disabled = true;
                    if (spinner) spinner.classList.remove('hidden');
                    if (label) label.textContent = 'Enviando para análisis…';
                });
            })();
            </script>
        @endif
    @endunless
</x-card>
