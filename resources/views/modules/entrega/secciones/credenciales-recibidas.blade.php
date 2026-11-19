@php
    $tipos = ['emision' => 'Emisión', 'reimpresion_historica' => 'Reimpresión histórica'];
    $fecha = static fn ($f) => $f?->format('d/m/Y H:i');
    $columnas = ['Fila', 'CUIP / nombre', 'Folio', 'Impresión efectiva', 'Impresiones', 'Observaciones'];
@endphp

<p class="mb-4 rounded-md border border-inst-200 bg-inst-50 p-3 text-xs leading-5 text-inst-900" data-proposito="credenciales-recibidas">
    Elementos de esta tarjeta con una impresión confirmada vigente. Aparecen aquí por su registro de impresión, no por una
    recepción física entre áreas, y <strong>no significa que la credencial se haya entregado</strong>.
</p>

<section aria-labelledby="recibidas-indicadores" class="mb-5">
    <h2 id="recibidas-indicadores" class="sr-only">Indicadores de credenciales impresas</h2>
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-kpi label="Elementos con credencial impresa" :value="number_format($conteos['elementos'])" hint="Personas distintas; una reimpresión no suma" icon="users" tone="success"/>
        <x-kpi label="Impresiones físicas efectivas" :value="number_format($conteos['impresiones_fisicas'])" hint="Tarjetas con resultado vigente «impresa»" icon="idcard" tone="info"/>
        <x-kpi label="Intentos registrados" :value="number_format($conteos['intentos'])" hint="Incluye fallidos y rectificados" icon="printer" tone="neutral"/>
        <x-kpi label="Con más de una impresión efectiva" :value="number_format($conteos['multiples'])" hint="Sin regla definida para elegir cuál se entrega" icon="copy" tone="warning"/>
    </div>
</section>

<x-card title="Credenciales impresas" icon="idcard" description="Un renglón por elemento; despliegue los intentos para ver cada tarjeta física registrada.">
    <form method="GET" action="{{ route('entregas.tarjeta', ['solicitud' => $tarjeta->id, 'seccion' => $seccion]) }}" class="panel-muted mb-4 p-4" role="search" aria-label="Buscar en credenciales impresas">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto]">
            <label class="field-label">
                CUIP, nombre o folio
                <input type="search" name="q" value="{{ $filtros['q'] ?? '' }}" maxlength="100" autocomplete="off">
            </label>
            <label class="field-label">
                Mostrar
                <select name="filtro">
                    <option value="">Todas las credenciales impresas</option>
                    @foreach ($opcionesFiltro as $clave => $etiqueta)
                        <option value="{{ $clave }}" @selected(($filtros['filtro'] ?? '') === $clave)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </label>
            <div class="flex items-end gap-2">
                <button type="submit" class="btn btn-primary flex-1">Buscar</button>
                <a href="{{ route('entregas.tarjeta', ['solicitud' => $tarjeta->id, 'seccion' => $seccion]) }}" class="btn btn-secondary">Limpiar</a>
            </div>
        </div>
    </form>

    @if ($filas->isNotEmpty())
        <div class="table-wrap">
            <table class="table-data">
                <caption class="sr-only">Credenciales impresas de la tarjeta {{ $numero }}</caption>
                <thead>
                    <tr>
                        @foreach ($columnas as $columna)
                            <th scope="col">{{ $columna }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($filas as $fila)
                        @php
                            $e = $fila['elemento'];
                            $nombre = $e->persona?->nombre ?: ($e->datos_solicitados['nombre'] ?? null);
                        @endphp
                        <tr data-elemento="{{ $e->id }}">
                            <td class="align-top text-xs tabular-nums text-tx-soft">{{ $e->numero_fila ?? '—' }}</td>
                            <td class="align-top">
                                <span class="block font-mono text-xs font-semibold text-tx">{{ $e->cuip ?: 'Sin CUIP' }}</span>
                                <span class="block text-2xs text-tx-soft">{{ $nombre ?: 'Sin nombre registrado' }}</span>
                            </td>
                            <td class="whitespace-nowrap align-top font-mono text-xs">{{ $e->credencialEmitida?->folio ?: '—' }}</td>
                            <td class="whitespace-nowrap align-top text-xs">
                                <span class="block font-mono">{{ $fecha($fila['primera_efectiva']) ?? '—' }}</span>
                                @if ($fila['efectivas'] > 1)
                                    <span class="block text-2xs text-tx-soft">Última: {{ $fecha($fila['ultima_efectiva']) }}</span>
                                @endif
                            </td>
                            <td class="align-top text-xs">
                                <span class="font-semibold tabular-nums text-inst-900">{{ $fila['efectivas'] }}</span> efectiva(s) de {{ $fila['intentos']->count() }}
                                <details class="mt-1" data-intentos="{{ $e->id }}">
                                    <summary class="cursor-pointer text-2xs font-medium text-inst-800 hover:underline">Ver intentos</summary>
                                    <ol class="mt-2 space-y-1.5">
                                        @foreach ($fila['intentos'] as $intento)
                                            <li class="rounded border border-slate-200 p-2 text-2xs leading-4" data-impresion="{{ $intento['id'] }}">
                                                <span class="font-semibold text-tx">Intento {{ $intento['numero'] }}</span>
                                                · registro #{{ $intento['id'] }}
                                                · {{ $tipos[$intento['tipo']] ?? 'Sin preparación vinculada' }}
                                                · {{ $fecha($intento['fecha']) ?? 'sin fecha' }}
                                                @if ($intento['usuario']) · {{ $intento['usuario'] }} @endif
                                                <span class="mt-1 flex flex-wrap gap-1">
                                                    <x-status-badge :label="$intento['vigente'] === 'impresa' ? 'Impresa' : 'Fallida'" :tone="$intento['vigente'] === 'impresa' ? 'success' : 'danger'" size="xs"/>
                                                    @if ($intento['rectificada'])
                                                        <x-status-badge :label="'Rectificada (registrada como '.$intento['registrado'].')'" tone="warning" size="xs"/>
                                                    @endif
                                                    @if ($intento['extemporanea'])
                                                        <x-status-badge label="Confirmación extemporánea" tone="neutral" size="xs"/>
                                                    @endif
                                                </span>
                                            </li>
                                        @endforeach
                                    </ol>
                                </details>
                            </td>
                            <td class="align-top">
                                <span class="flex flex-wrap gap-1">
                                    @if ($fila['con_reimpresion'])
                                        <x-status-badge label="Con reimpresión" tone="info" size="xs"/>
                                    @endif
                                    @if ($fila['efectivas'] > 1)
                                        <x-status-badge label="Varias impresiones efectivas" tone="warning" size="xs"/>
                                    @endif
                                    @if ($fila['posible_duplicado'])
                                        <x-status-badge label="Posible tarjeta duplicada" tone="danger" size="xs"/>
                                    @endif
                                    @if (! $fila['con_reimpresion'] && $fila['efectivas'] === 1 && ! $fila['posible_duplicado'])
                                        <span class="text-2xs text-tx-soft">—</span>
                                    @endif
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($filas->hasPages())
            <div class="mt-4">{{ $filas->links() }}</div>
        @endif
    @else
        <x-empty-table :columns="$columnas" :message="$sinFiltrar === 0
            ? 'Esta tarjeta aún no tiene credenciales con impresión efectiva: los elementos pendientes de biométricos o de impresión se consultan en Pendientes.'
            : 'Ninguna credencial impresa de esta tarjeta coincide con la búsqueda o el filtro.'"/>
    @endif
</x-card>
