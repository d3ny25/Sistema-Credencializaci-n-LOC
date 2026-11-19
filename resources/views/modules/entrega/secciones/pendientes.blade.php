@php
    $categorias = [
        'biometricos' => ['Pendiente de biométricos', 'warning'],
        'impresion' => ['Pendiente de impresión', 'process'],
        'loc' => ['Pendiente de conciliación LOC', 'omitido'],
    ];
    $vacios = [
        'biometricos' => 'Ningún elemento apto de esta tarjeta está pendiente de biométricos.',
        'impresion' => 'Ningún elemento de esta tarjeta está pendiente de impresión.',
        'loc' => 'Ningún elemento de esta tarjeta tiene impresión efectiva todavía: la categoría LOC solo aplica a credenciales impresas.',
    ];
    $columnas = ['Fila', 'CUIP / nombre', 'Folio', 'Categoría', 'Motivo', 'Fecha de referencia'];
    $filtroActivo = $filtros['filtro'] ?? '';
@endphp

<p class="mb-4 rounded-md border border-inst-200 bg-inst-50 p-3 text-xs leading-5 text-inst-900" data-proposito="pendientes">
    Elementos que aún tienen una etapa por cumplir antes de la entrega. Cada elemento aparece en una sola categoría, pero un elemento
    pendiente de conciliación LOC también figura en Credenciales recibidas porque ya está impreso. Las cifras por categoría no se suman.
</p>

<section aria-labelledby="pendientes-indicadores" class="mb-5">
    <h2 id="pendientes-indicadores" class="sr-only">Pendientes por categoría</h2>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <x-kpi label="Pendientes de biométricos" :value="number_format($conteos['biometricos'] ?? 0)" hint="Validación 4/4 incompleta o aún no ejecutada" icon="fingerprint" tone="warning"/>
        <x-kpi label="Pendientes de impresión" :value="number_format($conteos['impresion'] ?? 0)" hint="Biometría 4/4 y folio, sin impresión efectiva" icon="printer" tone="info"/>
        <x-kpi label="Pendientes de conciliación LOC" :value="number_format($conteos['loc'] ?? 0)" hint="Impresas; conciliación aún no implementada" icon="database" tone="neutral"/>
    </div>
    @if ($resumen['sin_prefiltro'] > 0 || $resumen['descartados'] > 0)
        <p class="mt-3 text-xs text-tx-soft">
            No se listan {{ $resumen['sin_prefiltro'] }} elemento(s) sin prefiltro ni {{ $resumen['descartados'] }} descartado(s): no tienen una etapa pendiente en estas categorías. Consulte el Panel.
        </p>
    @endif
</section>

<x-card title="Elementos pendientes" icon="clock" description="Motivo de espera según los estados y registros persistidos; la consulta no ejecuta validaciones.">
    <form method="GET" action="{{ route('entregas.tarjeta', ['solicitud' => $tarjeta->id, 'seccion' => $seccion]) }}" class="panel-muted mb-4 p-4" role="search" aria-label="Buscar en elementos pendientes">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto]">
            <label class="field-label">
                CUIP, nombre o folio
                <input type="search" name="q" value="{{ $filtros['q'] ?? '' }}" maxlength="100" autocomplete="off">
            </label>
            <label class="field-label">
                Categoría
                <select name="filtro">
                    <option value="">Todas las categorías</option>
                    @foreach ($opcionesFiltro as $clave => $etiqueta)
                        <option value="{{ $clave }}" @selected($filtroActivo === $clave)>{{ $etiqueta }}</option>
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
                <caption class="sr-only">Elementos pendientes de la tarjeta {{ $numero }}</caption>
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
                            [$catLabel, $catTono] = $categorias[$fila['categoria']];
                            $nombre = $e->persona?->nombre ?: ($e->datos_solicitados['nombre'] ?? null);
                        @endphp
                        <tr data-elemento="{{ $e->id }}" data-categoria="{{ $fila['categoria'] }}">
                            <td class="align-top text-xs tabular-nums text-tx-soft">{{ $e->numero_fila ?? '—' }}</td>
                            <td class="align-top">
                                <span class="block font-mono text-xs font-semibold text-tx">{{ $e->cuip ?: 'Sin CUIP' }}</span>
                                <span class="block text-2xs text-tx-soft">{{ $nombre ?: 'Sin nombre registrado' }}</span>
                            </td>
                            <td class="whitespace-nowrap align-top font-mono text-xs">{{ $e->credencialEmitida?->folio ?: '—' }}</td>
                            <td class="whitespace-nowrap align-top"><x-status-badge :label="$catLabel" :tone="$catTono" size="xs"/></td>
                            <td class="min-w-[16rem] align-top text-xs leading-5 text-tx">{{ $fila['motivo'] }}</td>
                            <td class="whitespace-nowrap align-top text-xs">
                                @if ($fila['fecha'])
                                    <span class="block font-mono">{{ $fila['fecha']->format('d/m/Y H:i') }}</span>
                                    <span class="block text-2xs text-tx-soft">{{ $fila['fecha_etiqueta'] }}</span>
                                @else
                                    <span class="text-2xs text-tx-soft" title="El sistema no conserva una fecha verificable para esta etapa">Sin fecha registrada</span>
                                @endif
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
        <x-empty-table :columns="$columnas" :message="match (true) {
            $sinFiltrar === 0 => 'Esta tarjeta no tiene elementos pendientes de biométricos, de impresión ni de conciliación LOC.',
            filled($filtros['q'] ?? null) => 'Ningún elemento pendiente de esta tarjeta coincide con la búsqueda.',
            default => $vacios[$filtroActivo] ?? 'No hay elementos pendientes con este filtro.',
        }"/>
    @endif
</x-card>
