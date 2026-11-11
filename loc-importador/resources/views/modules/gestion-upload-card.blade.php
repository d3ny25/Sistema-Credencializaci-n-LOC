{{-- Inserta este bloque dentro de la sección “Fuentes de información” de modules/gestion.blade.php,
     en el lugar de la tarjeta estática de Personal autorizado LOC. --}}
<div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex items-start gap-3">
        <div class="rounded-lg bg-slate-100 p-2 text-slate-700">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path d="M12 16V4m0 0L7 9m5-5 5 5M4 16v4h16v-4"/>
            </svg>
        </div>
        <div class="min-w-0 flex-1">
            <h2 class="font-semibold text-slate-900">Personal autorizado LOC</h2>
            <p class="mt-1 text-sm text-slate-600">Importa el padrón desde Excel antiguo (.xls). El archivo se valida antes de aplicar cambios.</p>
        </div>
    </div>
    <form class="mt-5 space-y-3" method="POST" action="{{ route('gestion.importaciones.subir') }}" enctype="multipart/form-data">
        @csrf
        <label for="archivo_loc" class="block text-sm font-medium text-slate-700">Archivo .xls</label>
        <input id="archivo_loc" name="archivo_loc" type="file" accept=".xls,application/vnd.ms-excel" required
            class="block w-full rounded-lg border border-slate-300 bg-white text-sm text-slate-700 file:mr-4 file:border-0 file:bg-slate-100 file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-slate-700 hover:file:bg-slate-200">
        @error('archivo_loc')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
        <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-700">Analizar archivo</button>
    </form>
    <p class="mt-3 text-xs text-slate-500">Se recomienda revisar el resumen y las filas rechazadas antes de confirmar.</p>
</div>
