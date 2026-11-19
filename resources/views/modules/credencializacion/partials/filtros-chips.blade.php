{{-- Chips de Filtros Activos (Fragmento Reactivo AJAX) --}}
@php
    $hasActiveFilters = !empty($filters) && count(array_filter($filters)) > 0;
@endphp

@if ($hasActiveFilters)
    <div class="flex flex-wrap items-center gap-1.5 pt-2 border-t border-slate-200 text-xs">
        <span class="mr-1 text-xs font-semibold uppercase tracking-wider text-tx-soft">Filtros aplicados:</span>

        @if(!empty($filters['q']))
            @php
                $urlWithoutQ = route('credencializacion.detalle', array_merge(['solicitud' => $solicitud->id], request()->except('q', 'page')));
            @endphp
            <span class="inline-flex items-center gap-1 rounded-full bg-inst-900 px-2.5 py-0.5 text-xs font-medium text-white">
                <span>Búsqueda:</span> <strong class="font-semibold">"{{ $filters['q'] }}"</strong>
                <a href="{{ $urlWithoutQ }}"
                   data-chip-url="{{ $urlWithoutQ }}"
                   class="ml-0.5 font-bold text-white/70 hover:text-white"
                   title="Quitar filtro">×</a>
            </span>
        @endif

        @if(!empty($filters['prefiltro']))
            @php
                $urlWithoutPrefiltro = route('credencializacion.detalle', array_merge(['solicitud' => $solicitud->id], request()->except('prefiltro', 'page')));
            @endphp
            <span class="inline-flex items-center gap-1 rounded-full bg-inst-900 px-2.5 py-0.5 text-xs font-medium text-white">
                <span>Prefiltro:</span> <strong class="font-semibold">{{ ucfirst($filters['prefiltro']) }}</strong>
                <a href="{{ $urlWithoutPrefiltro }}"
                   data-chip-url="{{ $urlWithoutPrefiltro }}"
                   class="ml-0.5 font-bold text-white/70 hover:text-white"
                   title="Quitar filtro">×</a>
            </span>
        @endif

        @if(!empty($filters['motivo']))
            @php
                $motivoLabel = $filters['motivo'] === 'historico' ? 'Histórico sin clasificar' : \App\Support\MotivoDescarte::label($filters['motivo']);
                $urlWithoutMotivo = route('credencializacion.detalle', array_merge(['solicitud' => $solicitud->id], request()->except('motivo', 'page')));
            @endphp
            <span class="inline-flex items-center gap-1 rounded-full bg-inst-900 px-2.5 py-0.5 text-xs font-medium text-white">
                <span>Motivo:</span> <strong class="font-semibold">{{ $motivoLabel }}</strong>
                <a href="{{ $urlWithoutMotivo }}"
                   data-chip-url="{{ $urlWithoutMotivo }}"
                   class="ml-0.5 font-bold text-white/70 hover:text-white"
                   title="Quitar filtro">×</a>
            </span>
        @endif

        @if(!empty($filters['agrupamiento']))
            @php
                $urlWithoutAgr = route('credencializacion.detalle', array_merge(['solicitud' => $solicitud->id], request()->except('agrupamiento', 'page')));
            @endphp
            <span class="inline-flex items-center gap-1 rounded-full bg-inst-900 px-2.5 py-0.5 text-xs font-medium text-white">
                <span>Agrupamiento:</span> <strong class="font-semibold">{{ ucfirst(str_replace('_', ' ', $filters['agrupamiento'])) }}</strong>
                <a href="{{ $urlWithoutAgr }}"
                   data-chip-url="{{ $urlWithoutAgr }}"
                   class="ml-0.5 font-bold text-white/70 hover:text-white"
                   title="Quitar filtro">×</a>
            </span>
        @endif

        @if(!empty($filters['clave']))
            @php
                $urlWithoutClave = route('credencializacion.detalle', array_merge(['solicitud' => $solicitud->id], request()->except('clave', 'page')));
            @endphp
            <span class="inline-flex items-center gap-1 rounded-full bg-inst-900 px-2.5 py-0.5 text-xs font-medium text-white">
                <span>Clave:</span> <strong class="font-mono font-bold">{{ strtoupper($filters['clave']) }}</strong>
                <a href="{{ $urlWithoutClave }}"
                   data-chip-url="{{ $urlWithoutClave }}"
                   class="ml-0.5 font-bold text-white/70 hover:text-white"
                   title="Quitar filtro">×</a>
            </span>
        @endif

        @if(!empty($filters['arma_corta']))
            @php
                $urlWithoutCorta = route('credencializacion.detalle', array_merge(['solicitud' => $solicitud->id], request()->except('arma_corta', 'page')));
            @endphp
            <span class="inline-flex items-center gap-1 rounded-full bg-inst-900 px-2.5 py-0.5 text-xs font-medium text-white">
                <span>Corta:</span> <strong class="font-semibold">{{ str_replace('_', ' ', $filters['arma_corta']) }}</strong>
                <a href="{{ $urlWithoutCorta }}"
                   data-chip-url="{{ $urlWithoutCorta }}"
                   class="ml-0.5 font-bold text-white/70 hover:text-white"
                   title="Quitar filtro">×</a>
            </span>
        @endif

        @if(!empty($filters['arma_larga']))
            @php
                $urlWithoutLarga = route('credencializacion.detalle', array_merge(['solicitud' => $solicitud->id], request()->except('arma_larga', 'page')));
            @endphp
            <span class="inline-flex items-center gap-1 rounded-full bg-inst-900 px-2.5 py-0.5 text-xs font-medium text-white">
                <span>Larga:</span> <strong class="font-semibold">{{ str_replace('_', ' ', $filters['arma_larga']) }}</strong>
                <a href="{{ $urlWithoutLarga }}"
                   data-chip-url="{{ $urlWithoutLarga }}"
                   class="ml-0.5 font-bold text-white/70 hover:text-white"
                   title="Quitar filtro">×</a>
            </span>
        @endif
    </div>
@endif

