@php
    $fecha = static fn ($f) => $f?->format('d/m/Y H:i');
    $titular = static fn ($e) => $e->persona?->nombre ?: ($e->datos_solicitados['nombre'] ?? null);
    $bandeja = route('entregas.tarjeta', ['solicitud' => $tarjeta->id, 'seccion' => 'entregas']);
@endphp

@if ($vista === null)
    <section aria-labelledby="entregas-indicadores" class="mb-5">
        <h2 id="entregas-indicadores" class="sr-only">Indicadores de entrega</h2>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <x-kpi label="Elementos de la tarjeta" :value="number_format($resumen['total'])" hint="Personas registradas" icon="users" tone="info"/>
            <x-kpi label="Credenciales físicas entregadas" :value="number_format($conteosEntrega['fisicas'])" hint="Tarjetas físicas incluidas en entregas" icon="idcard" tone="success"/>
            <x-kpi label="Elementos entregados" :value="number_format($resumen['entregadas'])" hint="Personas distintas con su credencial entregada" icon="truck" tone="success"/>
            <x-kpi label="Operaciones registradas" :value="number_format($conteosEntrega['operaciones'])" hint="Entregas individuales o parciales" icon="list" tone="neutral"/>
            <x-kpi label="Disponibles para entrega" :value="number_format($resumen['pendientes_entrega'])" hint="Una impresión efectiva, sin entregar" icon="inbox" tone="info"/>
        </div>
        @if ($resumen['anomalias_entrega'] > 0)
            <p class="mt-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-xs font-medium text-amber-900" role="status">
                {{ $resumen['anomalias_entrega'] }} elemento(s) tienen más de una impresión efectiva. En el procedimiento normal existe una sola tarjeta física por persona:
                no se ofrecen para entrega ni se elige una automáticamente hasta su revisión institucional.
            </p>
        @endif
    </section>

    <x-card title="Operaciones de entrega" icon="truck" description="Cada operación conserva quién recibió, quién entregó y la fecha y hora del servidor. Los registros no se editan ni se eliminan.">
        @can('deliver-credentials')
            <div class="mb-4 flex justify-end">
                <x-button :href="route('entregas.entrega.nueva', $tarjeta)" variant="primary"><x-icon name="plus" class="h-4 w-4"/> Nueva entrega</x-button>
            </div>
        @endcan

        @if ($operaciones->isNotEmpty())
            <div class="table-wrap">
                <table class="table-data">
                    <caption class="sr-only">Operaciones de entrega de la tarjeta {{ $numero }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">Fecha y hora</th>
                            <th scope="col">Recibió</th>
                            <th scope="col">Entregó</th>
                            <th scope="col" class="text-right">Credenciales</th>
                            <th scope="col"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($operaciones as $operacion)
                            <tr data-entrega="{{ $operacion->id }}">
                                <td class="whitespace-nowrap font-mono text-xs">{{ $fecha($operacion->entregada_at) }}</td>
                                <td class="text-xs font-medium text-tx">{{ $operacion->receptor_nombre }}</td>
                                <td class="text-xs text-tx-soft">{{ $operacion->entregadoPor?->name ?? '—' }}</td>
                                <td class="text-right text-xs font-semibold tabular-nums text-inst-900">{{ $operacion->detalles_count }}</td>
                                <td class="whitespace-nowrap text-right">
                                    <a href="{{ route('entregas.entrega.detalle', [$tarjeta, $operacion]) }}" class="btn btn-secondary btn-sm" aria-label="Consultar la entrega {{ $operacion->id }}">
                                        Consultar detalle <x-icon name="chevronRight" class="h-3.5 w-3.5"/>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($operaciones->hasPages())
                <div class="mt-4">{{ $operaciones->links() }}</div>
            @endif
        @else
            <x-empty-table :columns="['Fecha y hora', 'Recibió', 'Entregó', 'Credenciales', 'Acciones']" message="Aún no se registran entregas para esta tarjeta."/>
        @endif
    </x-card>

@elseif ($vista === 'nueva')
    @php
        $disponibles = $opciones['disponibles'];
        $noDisponibles = $opciones['no_disponibles'];
        $seleccionadas = array_map('intval', (array) old('impresiones', []));
        $situaciones = [
            'entregada' => ['Entregada', 'success'],
            'anomalia_multiple' => ['Varias impresiones efectivas', 'warning'],
            'no_emitida' => ['No emitida', 'neutral'],
            'sin_impresion' => ['Sin impresión efectiva', 'danger'],
        ];
        $sinCredencial = $resumen['total'] - $disponibles->count() - $noDisponibles->count();
    @endphp

    <div class="mb-4"><a href="{{ $bandeja }}" class="inline-flex items-center gap-1 text-sm font-medium text-inst-800 hover:underline"><x-icon name="chevronRight" class="h-4 w-4 rotate-180"/> Volver a las entregas</a></div>

    <x-card title="Nueva entrega" icon="truck" description="Seleccione las credenciales físicas que se entregan ahora. El responsable y la fecha y hora se registran automáticamente.">
        @if ($disponibles->isNotEmpty())
            <form method="POST" action="{{ route('entregas.entrega.confirmar', $tarjeta) }}" aria-label="Seleccionar credenciales para entregar">
                @csrf
                <div class="table-wrap">
                    <table class="table-data">
                        <caption class="sr-only">Credenciales disponibles para entrega de la tarjeta {{ $numero }}</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="w-10">
                                    <input type="checkbox" aria-label="Seleccionar todas las credenciales disponibles"
                                           onclick="this.closest('table').querySelectorAll('input[name=&quot;impresiones[]&quot;]').forEach(c => c.checked = this.checked)">
                                </th>
                                <th scope="col">Fila</th>
                                <th scope="col">CUIP / nombre</th>
                                <th scope="col">Folio</th>
                                <th scope="col">Impresión confirmada</th>
                                <th scope="col">Tarjeta física</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($disponibles as $opcion)
                                @php [$e, $i] = [$opcion['elemento'], $opcion['impresion']]; @endphp
                                <tr data-disponible="{{ $i->id }}">
                                    <td><input type="checkbox" name="impresiones[]" value="{{ $i->id }}" id="impresion-{{ $i->id }}" @checked(in_array($i->id, $seleccionadas, true))></td>
                                    <td class="text-xs tabular-nums text-tx-soft">{{ $e->numero_fila ?? '—' }}</td>
                                    <td>
                                        <label for="impresion-{{ $i->id }}" class="block cursor-pointer">
                                            <span class="block font-mono text-xs font-semibold text-tx">{{ $e->cuip ?: 'Sin CUIP' }}</span>
                                            <span class="block text-2xs text-tx-soft">{{ $titular($e) ?: 'Sin nombre registrado' }}</span>
                                        </label>
                                    </td>
                                    <td class="whitespace-nowrap font-mono text-xs">{{ $e->credencialEmitida?->folio ?: '—' }}</td>
                                    <td class="whitespace-nowrap font-mono text-xs">{{ $fecha($i->created_at) }}</td>
                                    <td class="text-xs">
                                        <span class="whitespace-nowrap">Registro #{{ $i->id }} · intento {{ $i->numero_intento }}</span>
                                        @if ($opcion['posible_duplicado'])
                                            <span class="mt-1 block" data-advertencia-duplicado="{{ $i->id }}">
                                                <x-status-badge label="Incidencia de posible duplicado abierta" tone="warning" size="xs"/>
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($disponibles->contains('posible_duplicado', true))
                    <p class="mt-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900" role="note">
                        Alguna credencial tiene abierta una incidencia de posible tarjeta duplicada, registrada en Impresión. Hoy tiene una sola impresión efectiva y puede entregarse;
                        la incidencia se resuelve en Impresión y biometría.
                    </p>
                @endif

                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,2fr)_auto] sm:items-end">
                    <label class="field-label">
                        Nombre completo de quien recibe
                        <input type="text" name="receptor_nombre" value="{{ old('receptor_nombre') }}" required minlength="3" maxlength="150" autocomplete="off">
                    </label>
                    <button type="submit" class="btn btn-primary">Revisar entrega</button>
                </div>
            </form>
        @else
            @php $causas = $noDisponibles->countBy('estado'); @endphp
            <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-5 text-sm text-tx" data-sin-disponibles>
                <p class="font-semibold text-inst-900">No hay credenciales disponibles para entregar en esta tarjeta.</p>
                <ul class="mt-2 list-inside list-disc space-y-0.5 text-xs text-tx-soft">
                    @foreach ($causas as $estado => $total)
                        <li>{{ $total }} {{ mb_strtolower($situaciones[$estado][0]) }}.</li>
                    @endforeach
                    @if ($sinCredencial > 0)
                        <li>{{ $sinCredencial }} elemento(s) sin credencial impresa todavía (consulte Pendientes).</li>
                    @endif
                </ul>
            </div>
        @endif
    </x-card>

    @if ($noDisponibles->isNotEmpty())
        <x-card class="mt-5" title="Credenciales no disponibles" icon="info" description="No se pueden seleccionar; el motivo proviene de los registros de emisión, impresión y entrega.">
            <div class="table-wrap">
                <table class="table-data">
                    <caption class="sr-only">Credenciales no disponibles para entrega</caption>
                    <thead>
                        <tr><th scope="col">Fila</th><th scope="col">CUIP / nombre</th><th scope="col">Folio</th><th scope="col">Situación</th><th scope="col">Motivo</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($noDisponibles as $fila)
                            @php [$sitLabel, $sitTono] = $situaciones[$fila['estado']]; $e = $fila['elemento']; @endphp
                            <tr data-no-disponible="{{ $e->id }}" data-estado="{{ $fila['estado'] }}">
                                <td class="text-xs tabular-nums text-tx-soft">{{ $e->numero_fila ?? '—' }}</td>
                                <td>
                                    <span class="block font-mono text-xs font-semibold text-tx">{{ $e->cuip ?: 'Sin CUIP' }}</span>
                                    <span class="block text-2xs text-tx-soft">{{ $titular($e) ?: 'Sin nombre registrado' }}</span>
                                </td>
                                <td class="whitespace-nowrap font-mono text-xs">{{ $e->credencialEmitida?->folio ?: '—' }}</td>
                                <td class="whitespace-nowrap"><x-status-badge :label="$sitLabel" :tone="$sitTono" size="xs"/></td>
                                <td class="min-w-[16rem] text-xs leading-5">{{ $fila['motivo'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif

@elseif ($vista === 'confirmar')
    <x-card title="Confirmar entrega" icon="check" description="Revise la operación. Al confirmar se registra de forma definitiva: no podrá editarse ni eliminarse.">
        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-2xs font-semibold uppercase tracking-wider text-tx-soft">Número de tarjeta</dt><dd class="font-mono font-semibold text-inst-900">{{ $numero }}</dd></div>
            <div><dt class="text-2xs font-semibold uppercase tracking-wider text-tx-soft">Recibe</dt><dd class="font-semibold text-inst-900" data-receptor>{{ $receptor }}</dd></div>
            <div><dt class="text-2xs font-semibold uppercase tracking-wider text-tx-soft">Credenciales físicas</dt><dd class="font-semibold tabular-nums text-inst-900">{{ $seleccion->count() }}</dd></div>
            <div><dt class="text-2xs font-semibold uppercase tracking-wider text-tx-soft">Entrega</dt><dd class="text-tx">{{ auth()->user()->name }} <span class="block text-2xs text-tx-soft">Fecha y hora del servidor al confirmar</span></dd></div>
        </dl>

        <div class="table-wrap mt-4">
            <table class="table-data">
                <caption class="sr-only">Credenciales incluidas en la entrega</caption>
                <thead><tr><th scope="col">Fila</th><th scope="col">CUIP / nombre</th><th scope="col">Folio</th><th scope="col">Tarjeta física</th></tr></thead>
                <tbody>
                    @foreach ($seleccion as $fila)
                        @php [$e, $i] = [$fila['elemento'], $fila['impresion']]; @endphp
                        <tr data-seleccionada="{{ $i->id }}">
                            <td class="text-xs tabular-nums text-tx-soft">{{ $e->numero_fila ?? '—' }}</td>
                            <td>
                                <span class="block font-mono text-xs font-semibold text-tx">{{ $e->cuip ?: 'Sin CUIP' }}</span>
                                <span class="block text-2xs text-tx-soft">{{ $titular($e) ?: 'Sin nombre registrado' }}</span>
                            </td>
                            <td class="whitespace-nowrap font-mono text-xs">{{ $e->credencialEmitida?->folio ?: '—' }}</td>
                            <td class="whitespace-nowrap text-xs">Registro #{{ $i->id }} · intento {{ $i->numero_intento }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <form method="POST" action="{{ route('entregas.entrega.store', $tarjeta) }}" class="mt-4 flex flex-wrap items-center justify-end gap-2"
              onsubmit="this.querySelector('[type=submit]').disabled = true">
            @csrf
            <input type="hidden" name="receptor_nombre" value="{{ $receptor }}">
            <input type="hidden" name="clave_operacion" value="{{ $clave }}">
            @foreach ($seleccion as $fila)
                <input type="hidden" name="impresiones[]" value="{{ $fila['impresion']->id }}">
            @endforeach
            <a href="{{ route('entregas.entrega.nueva', $tarjeta) }}" class="btn btn-secondary">Modificar selección</a>
            <button type="submit" class="btn btn-primary">Confirmar entrega</button>
        </form>
    </x-card>

@elseif ($vista === 'detalle')
    <div class="mb-4"><a href="{{ $bandeja }}" class="inline-flex items-center gap-1 text-sm font-medium text-inst-800 hover:underline"><x-icon name="chevronRight" class="h-4 w-4 rotate-180"/> Volver a las entregas</a></div>

    <x-card :title="'Entrega #'.$entrega->id" icon="truck" description="Registro de solo lectura.">
        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-2xs font-semibold uppercase tracking-wider text-tx-soft">Número de tarjeta</dt><dd class="font-mono font-semibold text-inst-900">{{ $numero }}</dd></div>
            <div><dt class="text-2xs font-semibold uppercase tracking-wider text-tx-soft">Recibió</dt><dd class="font-semibold text-inst-900">{{ $entrega->receptor_nombre }}</dd></div>
            <div><dt class="text-2xs font-semibold uppercase tracking-wider text-tx-soft">Entregó</dt><dd class="text-tx">{{ $entrega->entregadoPor?->name ?? '—' }}</dd></div>
            <div><dt class="text-2xs font-semibold uppercase tracking-wider text-tx-soft">Fecha y hora</dt><dd class="font-mono text-tx">{{ $fecha($entrega->entregada_at) }}</dd></div>
        </dl>

        <div class="table-wrap mt-4">
            <table class="table-data">
                <caption class="sr-only">Credenciales físicas de la entrega {{ $entrega->id }}</caption>
                <thead><tr><th scope="col">Fila</th><th scope="col">CUIP / nombre</th><th scope="col">Folio</th><th scope="col">Impresión confirmada</th><th scope="col">Tarjeta física</th></tr></thead>
                <tbody>
                    @foreach ($incluidas as $fila)
                        @php [$e, $i] = [$fila['elemento'], $fila['impresion']]; @endphp
                        <tr data-incluida="{{ $i->id }}">
                            <td class="text-xs tabular-nums text-tx-soft">{{ $e->numero_fila ?? '—' }}</td>
                            <td>
                                <span class="block font-mono text-xs font-semibold text-tx">{{ $e->cuip ?: 'Sin CUIP' }}</span>
                                <span class="block text-2xs text-tx-soft">{{ $titular($e) ?: 'Sin nombre registrado' }}</span>
                            </td>
                            <td class="whitespace-nowrap font-mono text-xs">{{ $e->credencialEmitida?->folio ?: '—' }}</td>
                            <td class="whitespace-nowrap font-mono text-xs">{{ $fecha($i->created_at) }}</td>
                            <td class="whitespace-nowrap text-xs">Registro #{{ $i->id }} · intento {{ $i->numero_intento }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>
@endif
