@extends('modules.entrega.layout')

@php
    $numero = $tarjeta->numero_tarjeta ?: 'Sin número asignado';
    [$estadoLabel, $estadoTono] = $estado;
    $pendientes = [
        'retenidas' => 'Credenciales retenidas con motivo documentado y su seguimiento hasta la liberación.',
        'conciliacion-loc' => 'Contraste contra un archivo LOC actualizado, independiente de Gestión de Información.',
        'historial' => 'Bitácora cronológica de movimientos y correcciones de la tarjeta.',
    ];
@endphp

@section('title', $secciones[$seccion].' · Tarjeta '.$numero)
@section('heading', 'Tarjeta '.$numero)
@section('subheading', $tarjeta->area_solicitante ?: 'Área solicitante no registrada')

@section('entrega')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('entregas') }}" class="inline-flex items-center gap-1 text-sm font-medium text-inst-800 hover:text-inst-900 hover:underline">
            <x-icon name="chevronRight" class="h-4 w-4 rotate-180"/> Volver a los números de tarjeta
        </a>
        <div class="flex flex-wrap items-center gap-2 text-xs text-tx-soft">
            <span>Oficio <span class="font-mono font-semibold text-tx">{{ $tarjeta->numero_oficio ?: '—' }}</span></span>
            <span aria-hidden="true">·</span>
            <span>Recepción {{ $tarjeta->fecha_recepcion?->format('d/m/Y') ?? '—' }}</span>
            <x-status-badge :label="$estadoLabel" :tone="$estadoTono" size="xs"/>
        </div>
    </div>

    <x-module-tabs :active="$seccion" :items="collect($secciones)->map(fn ($label, $clave) => [
        'key' => $clave,
        'label' => $label,
        'route' => 'entregas.tarjeta',
        'parameters' => ['solicitud' => $tarjeta->id, 'seccion' => $clave],
    ])->values()->all()"/>

    @if ($seccion === 'panel')
        @php
            $noDisponible = ['value' => 'No disponible', 'hint' => 'Funcionalidad aún no implementada', 'tone' => 'neutral'];
            $estadosCronologia = [
                'completada' => ['Completada', 'success'],
                'en_curso' => ['En curso', 'process'],
                'pendiente' => ['Pendiente', 'warning'],
                'sin_registro' => ['Sin registro histórico', 'neutral'],
                'no_implementada' => ['No implementada', 'omitido'],
            ];
            $etapas = [
                'impresa' => ['Impresa', 'success'],
                'pendiente_impresion' => ['Pendiente de impresión', 'process'],
                'pendiente_biometrico' => ['Pendiente de biométricos', 'warning'],
                'sin_validacion_biometrica' => ['Sin validación biométrica', 'neutral'],
                'sin_prefiltro' => ['Sin prefiltro', 'neutral'],
                'descartado' => ['Descartado en prefiltro', 'danger'],
            ];
            $fecha = static fn ($f) => $f?->format('d/m/Y H:i');
        @endphp

        <section aria-labelledby="indicadores-titulo" class="mb-5">
            <h2 id="indicadores-titulo" class="sr-only">Indicadores de avance</h2>
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <x-kpi label="Elementos de la tarjeta" :value="number_format($resumen['total'])" :hint="$resumen['aptos'].' aptos · '.$resumen['descartados'].' descartados'" icon="users" tone="info"/>
                <x-kpi label="Con credencial impresa" :value="number_format($resumen['impresas'])" hint="Elementos distintos con impresión vigente" icon="printer" tone="success"/>
                <x-kpi label="Pendientes de biométricos" :value="number_format($resumen['pendientes_biometrico'])" :hint="$resumen['sin_validacion_biometrica'].' aptos aún sin validación 4/4'" icon="fingerprint" tone="warning"/>
                <x-kpi label="Pendientes de impresión" :value="number_format($resumen['pendientes_impresion'])" hint="Biometría 4/4 y folio, sin impresión vigente" icon="clock" tone="info"/>
                <x-kpi label="Disponibles para entrega" :value="number_format($resumen['pendientes_entrega'])" :hint="$resumen['anomalias_entrega'] > 0 ? $resumen['anomalias_entrega'].' en revisión por varias impresiones efectivas' : 'Una impresión efectiva, sin entregar'" icon="inbox" tone="info"/>
                <x-kpi label="Entregadas" :value="number_format($resumen['entregadas'])" hint="Elementos con su credencial entregada" icon="truck" tone="success"/>
                <x-kpi label="Retenidas" :value="$noDisponible['value']" :hint="$noDisponible['hint']" icon="shield" :tone="$noDisponible['tone']"/>
            </div>
            @if ($resumen['emitidas_sin_impresion_vigente'] > 0)
                <p class="mt-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-xs font-medium text-amber-900" role="status">
                    {{ $resumen['emitidas_sin_impresion_vigente'] }} credencial(es) figuran como emitidas pero su impresión fue rectificada como fallida: no se cuentan como impresas y requieren revisión institucional.
                </p>
            @endif
        </section>

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-5">
            <x-card class="xl:col-span-2" title="Seguimiento por etapas" icon="list" description="Solo se muestran fechas registradas en el sistema.">
                <ol class="space-y-3">
                    @foreach ($cronologia as $paso)
                        @php [$pasoLabel, $pasoTono] = $estadosCronologia[$paso['estado']]; @endphp
                        <li class="rounded-lg border border-slate-200 p-3" data-etapa="{{ $paso['clave'] }}" data-estado="{{ $paso['estado'] }}">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <span class="text-sm font-semibold text-inst-900">{{ $loop->iteration }}. {{ $paso['etapa'] }}</span>
                                <x-status-badge :label="$pasoLabel" :tone="$pasoTono" size="xs"/>
                            </div>
                            @if ($paso['desde'])
                                <p class="mt-1 font-mono text-2xs text-tx">
                                    {{ $fecha($paso['desde']) }}@if ($paso['hasta']) — {{ $fecha($paso['hasta']) }}@endif
                                </p>
                            @endif
                            <p class="mt-1 text-xs leading-5 text-tx-soft">{{ $paso['detalle'] }}</p>
                        </li>
                    @endforeach
                </ol>
            </x-card>

            <x-card class="xl:col-span-3" title="Elementos de la tarjeta" icon="users" description="Etapa actual de cada elemento; un elemento con varias impresiones se cuenta una sola vez.">
                <form method="GET" action="{{ route('entregas.tarjeta', ['solicitud' => $tarjeta->id, 'seccion' => 'panel']) }}" class="mb-4 flex flex-wrap items-end gap-2" aria-label="Filtrar elementos por etapa">
                    <label class="field-label min-w-[14rem] flex-1">
                        Etapa
                        <select name="etapa">
                            <option value="">Todas las etapas</option>
                            @foreach ($etapas as $clave => [$etiqueta])
                                <option value="{{ $clave }}" @selected($etapaFiltro === $clave)>{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit" class="btn btn-secondary">Filtrar</button>
                </form>

                @if ($elementos->isNotEmpty())
                    <div class="table-wrap">
                        <table class="table-data">
                            <caption class="sr-only">Elementos de la tarjeta {{ $numero }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Fila</th>
                                    <th scope="col">CUIP / nombre</th>
                                    <th scope="col">Folio</th>
                                    <th scope="col">Etapa</th>
                                    <th scope="col" class="text-right">Impresiones vigentes</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($elementos as $fila)
                                    @php
                                        $e = $fila['elemento'];
                                        [$etapaLabel, $etapaTono] = $etapas[$fila['etapa']];
                                        $nombre = $e->persona?->nombre ?: ($e->datos_solicitados['nombre'] ?? null);
                                    @endphp
                                    <tr>
                                        <td class="text-xs tabular-nums text-tx-soft">{{ $e->numero_fila ?? '—' }}</td>
                                        <td>
                                            <span class="block font-mono text-xs font-semibold text-tx">{{ $e->cuip ?: 'Sin CUIP' }}</span>
                                            <span class="block text-2xs text-tx-soft">{{ $nombre ?: 'Sin nombre registrado' }}</span>
                                        </td>
                                        <td class="whitespace-nowrap font-mono text-xs">{{ $e->credencialEmitida?->folio ?: '—' }}</td>
                                        <td class="whitespace-nowrap"><x-status-badge :label="$etapaLabel" :tone="$etapaTono" size="xs"/></td>
                                        <td class="text-right text-xs tabular-nums" title="Una reimpresión vigente suma un intento, no un elemento">{{ $fila['impresas'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($elementos->hasPages())
                        <div class="mt-4">{{ $elementos->links() }}</div>
                    @endif
                @else
                    <x-empty-table :columns="['Fila', 'CUIP / nombre', 'Folio', 'Etapa', 'Impresiones vigentes']"
                                   :message="$etapaFiltro !== '' ? 'Ningún elemento de esta tarjeta está en la etapa seleccionada.' : 'La tarjeta aún no tiene elementos cargados.'"/>
                @endif
            </x-card>
        </div>
    @elseif (in_array($seccion, ['credenciales-recibidas', 'pendientes', 'entregas', 'expediente-documental'], true))
        @include('modules.entrega.secciones.'.$seccion)
    @else
        <x-card :title="$secciones[$seccion]" icon="info" description="Sección pendiente de implementación.">
            <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-sm leading-6 text-tx" data-seccion-pendiente="{{ $seccion }}">
                <p class="font-semibold text-inst-900">Esta sección aún no está disponible.</p>
                <p class="mt-1 text-tx-soft">{{ $pendientes[$seccion] }}</p>
                <p class="mt-3 text-xs text-tx-soft">No se registran ni se muestran operaciones hasta que la sección se implemente. Consulte el <a href="{{ route('entregas.tarjeta', ['solicitud' => $tarjeta->id, 'seccion' => 'panel']) }}" class="font-medium text-inst-800 underline">Panel</a> para el avance actual de la tarjeta.</p>
            </div>
        </x-card>
    @endif
@endsection
