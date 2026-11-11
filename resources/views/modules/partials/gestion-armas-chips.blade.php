<div class="flex flex-wrap items-center gap-2">
    <span class="mr-1 text-xs font-semibold uppercase tracking-wider text-tx-soft">Filtro rápido:</span>
    <a href="{{ route('gestion.armamento.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch])) }}"
       data-filter-chip
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ empty($actionFilter) && !$onlyErrors ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">
        <span>Todos</span>
        <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ empty($actionFilter) && !$onlyErrors ? 'bg-slate-700 text-white' : 'bg-slate-200 text-slate-600' }}">
            {{ number_format((int) $batch->total_leidas) }}
        </span>
    </a>
    <a href="{{ route('gestion.armamento.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch, 'accion' => 'nuevo'])) }}"
       data-filter-chip
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ ($actionFilter ?? '') === 'nuevo' ? 'bg-emerald-700 text-white' : 'bg-emerald-50 text-emerald-800 hover:bg-emerald-100' }}">
        <span>Nuevas</span>
        <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ ($actionFilter ?? '') === 'nuevo' ? 'bg-emerald-800 text-white' : 'bg-emerald-200/70 text-emerald-800' }}">
            {{ number_format((int) ($counts['nuevo'] ?? 0)) }}
        </span>
    </a>
    <a href="{{ route('gestion.armamento.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch, 'accion' => 'actualizar'])) }}"
       data-filter-chip
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ ($actionFilter ?? '') === 'actualizar' ? 'bg-blue-700 text-white' : 'bg-blue-50 text-blue-800 hover:bg-blue-100' }}">
        <span>Actualizaciones</span>
        <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ ($actionFilter ?? '') === 'actualizar' ? 'bg-blue-800 text-white' : 'bg-blue-200/70 text-blue-800' }}">
            {{ number_format((int) ($counts['actualizar'] ?? 0)) }}
        </span>
    </a>
    <a href="{{ route('gestion.armamento.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch, 'accion' => 'sin_cambios'])) }}"
       data-filter-chip
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ ($actionFilter ?? '') === 'sin_cambios' ? 'bg-slate-700 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">
        <span>Sin cambios</span>
        <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ ($actionFilter ?? '') === 'sin_cambios' ? 'bg-slate-800 text-white' : 'bg-slate-200 text-slate-600' }}">
            {{ number_format((int) ($counts['sin_cambios'] ?? 0)) }}
        </span>
    </a>
    <a href="{{ route('gestion.armamento.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch, 'accion' => 'requieren_correccion'])) }}"
       data-filter-chip
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ in_array(($actionFilter ?? ''), ['requieren_correccion', 'conflicto', 'rechazado'], true) || $onlyErrors ? 'bg-red-700 text-white' : 'bg-red-50 text-red-800 hover:bg-red-100' }}">
        <span>Excepciones</span>
        <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ in_array(($actionFilter ?? ''), ['requieren_correccion', 'conflicto', 'rechazado'], true) || $onlyErrors ? 'bg-red-800 text-white' : 'bg-red-200/70 text-red-800' }}">
            {{ number_format((int) ($counts['conflicto'] ?? 0) + (int) ($counts['rechazado'] ?? 0)) }}
        </span>
    </a>
    @if ((int) ($counts['omitido'] ?? 0) > 0)
        <a href="{{ route('gestion.armamento.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch, 'accion' => 'omitido'])) }}"
           data-filter-chip
           class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ ($actionFilter ?? '') === 'omitido' ? 'bg-purple-700 text-white' : 'bg-purple-50 text-purple-800 hover:bg-purple-100' }}">
            <span>Omitidas</span>
            <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ ($actionFilter ?? '') === 'omitido' ? 'bg-purple-800 text-white' : 'bg-purple-200/70 text-purple-800' }}">
                {{ number_format((int) $counts['omitido']) }}
            </span>
        </a>
    @endif
</div>

