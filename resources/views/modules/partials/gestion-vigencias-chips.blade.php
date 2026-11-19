@php
    $structuralErrors = $structural_errors_count ?? (($counts['conflicto'] ?? 0) + ($counts['rechazado'] ?? 0));
@endphp
<div class="flex flex-wrap items-center gap-2">
    <span class="mr-1 text-xs font-semibold uppercase tracking-wider text-tx-soft">Filtro rápido:</span>
    <a href="{{ route('gestion.vigencias.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch])) }}"
       data-filter-chip
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ empty($action_filter) && !$only_errors ? 'bg-inst-900 text-white ring-1 ring-inst-900' : 'bg-white text-tx ring-1 ring-slate-200 hover:bg-slate-50' }}">
        <span>Todos</span>
        <span class="rounded-full px-1.5 py-0.5 text-2xs {{ empty($action_filter) && !$only_errors ? 'bg-white/20 text-white' : 'bg-slate-100 text-tx-soft' }}">
            {{ number_format($batch->total_leidas) }}
        </span>
    </a>
    <a href="{{ route('gestion.vigencias.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch, 'accion' => 'nuevo_conciliado'])) }}"
       data-filter-chip
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ $action_filter === 'nuevo_conciliado' ? 'bg-inst-900 text-white ring-1 ring-inst-900' : 'bg-white text-tx ring-1 ring-slate-200 hover:bg-slate-50' }}">
        <span>Conciliadas</span>
        <span class="rounded-full px-1.5 py-0.5 text-2xs {{ $action_filter === 'nuevo_conciliado' ? 'bg-white/20 text-white' : 'bg-emerald-50 text-emerald-800' }}">
            {{ number_format($counts['nuevo_conciliado'] ?? 0) }}
        </span>
    </a>
    <a href="{{ route('gestion.vigencias.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch, 'accion' => 'nuevo_sin_enlace'])) }}"
       data-filter-chip
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ $action_filter === 'nuevo_sin_enlace' ? 'bg-inst-900 text-white ring-1 ring-inst-900' : 'bg-white text-tx ring-1 ring-slate-200 hover:bg-slate-50' }}">
        <span>Sin enlace</span>
        <span class="rounded-full px-1.5 py-0.5 text-2xs {{ $action_filter === 'nuevo_sin_enlace' ? 'bg-white/20 text-white' : 'bg-blue-50 text-blue-800' }}">
            {{ number_format($counts['nuevo_sin_enlace'] ?? 0) }}
        </span>
    </a>
    <a href="{{ route('gestion.vigencias.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch, 'accion' => 'cancelacion'])) }}"
       data-filter-chip
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ $action_filter === 'cancelacion' ? 'bg-inst-900 text-white ring-1 ring-inst-900' : 'bg-white text-tx ring-1 ring-slate-200 hover:bg-slate-50' }}">
        <span>Cancelaciones</span>
        <span class="rounded-full px-1.5 py-0.5 text-2xs {{ $action_filter === 'cancelacion' ? 'bg-white/20 text-white' : 'bg-amber-50 text-amber-800' }}">
            {{ number_format($counts['cancelacion'] ?? 0) }}
        </span>
    </a>
    <a href="{{ route('gestion.vigencias.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch, 'accion' => 'sin_cambios'])) }}"
       data-filter-chip
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ $action_filter === 'sin_cambios' ? 'bg-inst-900 text-white ring-1 ring-inst-900' : 'bg-white text-tx ring-1 ring-slate-200 hover:bg-slate-50' }}">
        <span>Sin cambios / Act.</span>
        <span class="rounded-full px-1.5 py-0.5 text-2xs {{ $action_filter === 'sin_cambios' ? 'bg-white/20 text-white' : 'bg-slate-100 text-tx-soft' }}">
            {{ number_format(($counts['sin_cambios'] ?? 0) + ($counts['actualizar'] ?? 0)) }}
        </span>
    </a>
    <a href="{{ route('gestion.vigencias.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch, 'solo_errores' => '1'])) }}"
       data-filter-chip
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ $only_errors || in_array($action_filter, ['conflicto', 'rechazado'], true) ? 'bg-inst-900 text-white ring-1 ring-inst-900' : 'bg-white text-tx ring-1 ring-slate-200 hover:bg-slate-50' }}">
        <span>Errores</span>
        <span class="rounded-full px-1.5 py-0.5 text-2xs {{ $only_errors || in_array($action_filter, ['conflicto', 'rechazado'], true) ? 'bg-white/20 text-white' : 'bg-red-50 text-red-800' }}">
            {{ number_format($structuralErrors) }}
        </span>
    </a>
    @if (($counts['omitido'] ?? 0) > 0)
        <a href="{{ route('gestion.vigencias.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch, 'accion' => 'omitido'])) }}"
           data-filter-chip
           class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ $action_filter === 'omitido' ? 'bg-inst-900 text-white ring-1 ring-inst-900' : 'bg-white text-tx ring-1 ring-slate-200 hover:bg-slate-50' }}">
            <span>Omitidas</span>
            <span class="rounded-full px-1.5 py-0.5 text-2xs {{ $action_filter === 'omitido' ? 'bg-white/20 text-white' : 'bg-purple-50 text-purple-800' }}">
                {{ number_format($counts['omitido']) }}
            </span>
        </a>
    @endif
</div>

