<div class="flex flex-wrap items-center gap-2">
    <span class="mr-1 text-xs font-semibold uppercase tracking-wider text-tx-soft">Filtro rápido:</span>
    <a href="{{ route('gestion.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch])) }}"
       data-filter-chip
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ empty($actionFilter) && !$onlyErrors ? 'bg-inst-900 text-white ring-1 ring-inst-900' : 'bg-white text-tx ring-1 ring-slate-200 hover:bg-slate-50' }}">
        <span>Todos</span>
        <span class="rounded-full px-1.5 py-0.5 text-2xs {{ empty($actionFilter) && !$onlyErrors ? 'bg-white/20 text-white' : 'bg-slate-100 text-tx-soft' }}">
            {{ number_format($batch->total_leidas) }}
        </span>
    </a>
    <a href="{{ route('gestion.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch, 'accion' => 'nuevo'])) }}"
       data-filter-chip
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ $actionFilter === 'nuevo' ? 'bg-inst-900 text-white ring-1 ring-inst-900' : 'bg-white text-tx ring-1 ring-slate-200 hover:bg-slate-50' }}">
        <span>Nuevos</span>
        <span class="rounded-full px-1.5 py-0.5 text-2xs {{ $actionFilter === 'nuevo' ? 'bg-white/20 text-white' : 'bg-emerald-50 text-emerald-800' }}">
            {{ number_format((int) ($counts['nuevo'] ?? 0)) }}
        </span>
    </a>
    <a href="{{ route('gestion.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch, 'accion' => 'actualizar'])) }}"
       data-filter-chip
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ $actionFilter === 'actualizar' ? 'bg-inst-900 text-white ring-1 ring-inst-900' : 'bg-white text-tx ring-1 ring-slate-200 hover:bg-slate-50' }}">
        <span>Actualizados</span>
        <span class="rounded-full px-1.5 py-0.5 text-2xs {{ $actionFilter === 'actualizar' ? 'bg-white/20 text-white' : 'bg-blue-50 text-blue-800' }}">
            {{ number_format((int) ($counts['actualizar'] ?? 0)) }}
        </span>
    </a>
    <a href="{{ route('gestion.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch, 'accion' => 'sin_cambios'])) }}"
       data-filter-chip
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ $actionFilter === 'sin_cambios' ? 'bg-inst-900 text-white ring-1 ring-inst-900' : 'bg-white text-tx ring-1 ring-slate-200 hover:bg-slate-50' }}">
        <span>Sin cambios</span>
        <span class="rounded-full px-1.5 py-0.5 text-2xs {{ $actionFilter === 'sin_cambios' ? 'bg-white/20 text-white' : 'bg-slate-100 text-tx-soft' }}">
            {{ number_format((int) ($counts['sin_cambios'] ?? 0)) }}
        </span>
    </a>
    <a href="{{ route('gestion.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch, 'accion' => 'requieren_correccion'])) }}"
       data-filter-chip
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ in_array($actionFilter, ['requieren_correccion', 'conflicto', 'rechazado'], true) || $onlyErrors ? 'bg-inst-900 text-white ring-1 ring-inst-900' : 'bg-white text-tx ring-1 ring-slate-200 hover:bg-slate-50' }}">
        <span>Requieren Corrección</span>
        <span class="rounded-full px-1.5 py-0.5 text-2xs {{ in_array($actionFilter, ['requieren_correccion', 'conflicto', 'rechazado'], true) || $onlyErrors ? 'bg-white/20 text-white' : 'bg-red-50 text-red-800' }}">
            {{ number_format($blockingErrors) }}
        </span>
    </a>
    @if (($counts['omitido'] ?? 0) > 0)
        <a href="{{ route('gestion.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch, 'accion' => 'omitido'])) }}"
           data-filter-chip
           class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ $actionFilter === 'omitido' ? 'bg-inst-900 text-white ring-1 ring-inst-900' : 'bg-white text-tx ring-1 ring-slate-200 hover:bg-slate-50' }}">
            <span>Omitidas</span>
            <span class="rounded-full px-1.5 py-0.5 text-2xs {{ $actionFilter === 'omitido' ? 'bg-white/20 text-white' : 'bg-purple-50 text-purple-800' }}">
                {{ number_format((int) ($counts['omitido'] ?? 0)) }}
            </span>
        </a>
    @endif
</div>

