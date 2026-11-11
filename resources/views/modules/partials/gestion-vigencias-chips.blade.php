@php
    $structuralErrors = $structural_errors_count ?? (($counts['conflicto'] ?? 0) + ($counts['rechazado'] ?? 0));
@endphp
<div class="flex flex-wrap items-center gap-2">
    <span class="mr-1 text-xs font-semibold uppercase tracking-wider text-tx-soft">Filtro rápido:</span>
    <a href="{{ route('gestion.vigencias.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch])) }}"
       data-filter-chip
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ empty($action_filter) && !$only_errors ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">
        <span>Todos</span>
        <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ empty($action_filter) && !$only_errors ? 'bg-slate-700 text-white' : 'bg-slate-200 text-slate-600' }}">
            {{ number_format($batch->total_leidas) }}
        </span>
    </a>
    <a href="{{ route('gestion.vigencias.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch, 'accion' => 'nuevo_conciliado'])) }}"
       data-filter-chip
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ $action_filter === 'nuevo_conciliado' ? 'bg-emerald-700 text-white' : 'bg-emerald-50 text-emerald-800 hover:bg-emerald-100' }}">
        <span>Conciliadas</span>
        <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $action_filter === 'nuevo_conciliado' ? 'bg-emerald-800 text-white' : 'bg-emerald-200/70 text-emerald-800' }}">
            {{ number_format($counts['nuevo_conciliado'] ?? 0) }}
        </span>
    </a>
    <a href="{{ route('gestion.vigencias.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch, 'accion' => 'nuevo_sin_enlace'])) }}"
       data-filter-chip
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ $action_filter === 'nuevo_sin_enlace' ? 'bg-blue-700 text-white' : 'bg-blue-50 text-blue-800 hover:bg-blue-100' }}">
        <span>Sin enlace</span>
        <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $action_filter === 'nuevo_sin_enlace' ? 'bg-blue-800 text-white' : 'bg-blue-200/70 text-blue-800' }}">
            {{ number_format($counts['nuevo_sin_enlace'] ?? 0) }}
        </span>
    </a>
    <a href="{{ route('gestion.vigencias.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch, 'accion' => 'cancelacion'])) }}"
       data-filter-chip
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ $action_filter === 'cancelacion' ? 'bg-amber-700 text-white' : 'bg-amber-50 text-amber-800 hover:bg-amber-100' }}">
        <span>Cancelaciones</span>
        <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $action_filter === 'cancelacion' ? 'bg-amber-800 text-white' : 'bg-amber-200/70 text-amber-800' }}">
            {{ number_format($counts['cancelacion'] ?? 0) }}
        </span>
    </a>
    <a href="{{ route('gestion.vigencias.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch, 'accion' => 'sin_cambios'])) }}"
       data-filter-chip
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ $action_filter === 'sin_cambios' ? 'bg-slate-700 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">
        <span>Sin cambios / Act.</span>
        <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $action_filter === 'sin_cambios' ? 'bg-slate-800 text-white' : 'bg-slate-200 text-slate-600' }}">
            {{ number_format(($counts['sin_cambios'] ?? 0) + ($counts['actualizar'] ?? 0)) }}
        </span>
    </a>
    <a href="{{ route('gestion.vigencias.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch, 'solo_errores' => '1'])) }}"
       data-filter-chip
       class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ $only_errors || in_array($action_filter, ['conflicto', 'rechazado'], true) ? 'bg-red-700 text-white' : 'bg-red-50 text-red-800 hover:bg-red-100' }}">
        <span>Errores</span>
        <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $only_errors || in_array($action_filter, ['conflicto', 'rechazado'], true) ? 'bg-red-800 text-white' : 'bg-red-200/70 text-red-800' }}">
            {{ number_format($structuralErrors) }}
        </span>
    </a>
    @if (($counts['omitido'] ?? 0) > 0)
        <a href="{{ route('gestion.vigencias.importaciones.preview', array_merge(request()->except(['accion', 'solo_errores', 'page']), ['batch' => $batch, 'accion' => 'omitido'])) }}"
           data-filter-chip
           class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition-colors {{ $action_filter === 'omitido' ? 'bg-purple-700 text-white' : 'bg-purple-50 text-purple-800 hover:bg-purple-100' }}">
            <span>Omitidas</span>
            <span class="rounded-full px-1.5 py-0.5 text-[10px] {{ $action_filter === 'omitido' ? 'bg-purple-800 text-white' : 'bg-purple-200/70 text-purple-800' }}">
                {{ number_format($counts['omitido']) }}
            </span>
        </a>
    @endif
</div>

