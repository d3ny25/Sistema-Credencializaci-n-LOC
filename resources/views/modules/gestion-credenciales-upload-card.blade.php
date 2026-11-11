<x-card class="flex flex-col h-full" title="Base de credenciales" description="Historial institucional de credenciales, vigencias y asociación con arma corta y larga.">
    <div class="mb-4 flex justify-end">
        <x-status-badge label="Análisis previo" tone="info"/>
    </div>

    @unless ($credentials_import_schema_ready)
        <div class="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950" role="alert">
            La importación no está habilitada en esta base: falta la migración de soporte para vigencias (2026_10_24_000001).
        </div>
    @else
        @if (auth()->user()?->getAttribute('local_demo_access'))
            <div class="rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="status">
                El modo demo es de solo lectura. Se requiere una cuenta administrativa real para analizar el archivo de vigencias.
            </div>
        @else
            <form id="credentials-upload-form" class="flex flex-col flex-1 justify-between space-y-4" method="POST"
                action="{{ route('gestion.vigencias.importaciones.subir') }}"
                enctype="multipart/form-data" data-max-kb="{{ $max_upload_kilobytes }}">
                @csrf

                <div class="space-y-4">
                    <x-file-dropzone 
                        name="archivo_vigencias" 
                        id="archivo_vigencias"
                        label="Selecciona o arrastra el padrón de vigencias (Vigencia.xls)"
                        help="Excel binario .xls · máximo {{ number_format($max_upload_kilobytes / 1024, 1) }} MB · no se admiten .xlsx"
                        accept=".xls,application/vnd.ms-excel"
                        :allowed-extensions="['xls']"
                        :max-kilobytes="$max_upload_kilobytes"
                        button-text="Seleccionar archivo"
                        required />

                    <p id="credentials-file-details" class="min-h-5 text-sm text-tx-soft" aria-live="polite">
                        Las 40 columnas originales se concilian contra Personal y Armamento LOC; arma corta y larga se identifican por posición.
                    </p>
                </div>

                <div class="mt-auto flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
                    <p class="max-w-xl text-xs leading-5 text-tx-soft">El análisis se procesa en cola y genera una vista previa. Los estados operativos no se actualizan automáticamente y las filas no conciliadas requieren revisión.</p>
                    <button id="credentials-upload-submit" type="submit"
                        class="inline-flex items-center justify-center gap-2 rounded-md bg-inst-700 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-inst-900 focus:outline-none focus:ring-2 focus:ring-inst-700 focus:ring-offset-1 disabled:cursor-wait disabled:opacity-70">
                        <svg id="credentials-upload-spinner" class="hidden h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                        </svg>
                        <span id="credentials-upload-submit-label">Analizar vigencias</span>
                    </button>
                </div>
            </form>

            <script>
            (() => {
                const form = document.getElementById('credentials-upload-form');
                if (!form) return;
                form.addEventListener('submit', () => {
                    const btn = document.getElementById('credentials-upload-submit');
                    const spinner = document.getElementById('credentials-upload-spinner');
                    const label = document.getElementById('credentials-upload-submit-label');
                    if (btn) btn.disabled = true;
                    if (spinner) spinner.classList.remove('hidden');
                    if (label) label.textContent = 'Analizando vigencias…';
                });
            })();
            </script>
        @endif
    @endunless
</x-card>
