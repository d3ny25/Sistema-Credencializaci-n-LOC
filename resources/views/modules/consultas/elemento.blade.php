@php
    $secciones = \App\Services\ConsultaExpedienteService::SECCIONES;
    $fecha = static fn ($f) => $f?->format('d/m/Y');
    $noRegistrada = '<span class="text-tx-soft">No registrada</span>';
    $tonoEstatus = ['ALTA' => 'success', 'BAJA' => 'neutral', 'SITUACION ESPECIAL' => 'warning', 'CANCELACION' => 'danger'];
    // Solo campos con valor: un dato vacío no se presenta como información.
    $campos = static fn (array $pares) => array_filter($pares, static fn ($v) => filled($v));
    $identificacion = $campos(['CUIP' => $persona->cuip, 'CURP' => $persona->curp, 'RFC' => $persona->rfc, 'ID_PERSONAL' => $persona->personal_id]);
    $ubicacion = $campos(['Región' => $persona->region, 'Municipio' => $persona->municipio, 'Subdirección' => $persona->subdireccion, 'Agrupamiento' => $persona->agrupamiento,
        'Cuerpo de seguridad' => $persona->cuerpo_seguridad, 'Categoría' => $persona->categoria, 'Estatus en el padrón LOC' => $persona->estatus]);
@endphp

<div class="mb-4 flex flex-wrap items-end justify-between gap-3" data-expediente="elemento">
    <div class="min-w-0">
        <a href="{{ route('consultas') }}" class="inline-flex items-center gap-1 text-sm font-medium text-inst-800 hover:underline"><x-icon name="chevronRight" class="h-4 w-4 rotate-180"/> Volver a Consultas</a>
        <h2 class="mt-2 text-xl font-semibold tracking-tight text-inst-900">{{ $persona->nombre }}</h2>
        <p class="mt-0.5 font-mono text-xs text-tx-soft">CUIP {{ $persona->cuip }}</p>
    </div>
    <x-status-badge label="Expediente de elemento del padrón" tone="info" size="xs"/>
</div>

<x-module-tabs :active="$seccion" :items="collect($secciones)->map(fn ($label, $clave) => ['key' => $clave, 'label' => $label, 'route' => 'consultas.elemento', 'parameters' => ['persona' => $persona->id, 'seccion' => $clave]])->values()->all()"/>

@php
    // Bloque reutilizado en Resumen y Vigencias: registro cronológicamente más reciente según la política canónica.
    $situacion = function (array $v) {
        if ($v['ultima'] === null) {
            return '<p class="text-sm text-tx-soft" data-situacion="sin-registros">No hay registros de vigencia con vínculo verificado al padrón.</p>';
        }
        if (! $v['determinable']) {
            return '<p class="text-sm font-semibold text-amber-900" data-situacion="no-determinable">Situación administrativa no determinable con los registros disponibles.</p>'
                .'<p class="mt-1 text-xs text-tx-soft">Hay varios registros sin fecha comparable o con la misma fecha; no se elige uno por su orden de captura.</p>';
        }

        return null;
    };
@endphp

@if ($seccion === 'resumen')
    @php $mensaje = $situacion($datos); $u = $datos['ultima']; @endphp
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <x-card title="Identificación" icon="idcard">
            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                @foreach ($identificacion as $etiqueta => $valor)
                    <div><dt class="text-2xs font-semibold uppercase tracking-wider text-tx-soft">{{ $etiqueta }}</dt><dd class="font-mono text-tx">{{ $valor }}</dd></div>
                @endforeach
            </dl>
            @if ($ubicacion !== [])
                <dl class="mt-4 grid grid-cols-1 gap-3 border-t border-slate-100 pt-4 text-sm sm:grid-cols-2">
                    @foreach ($ubicacion as $etiqueta => $valor)
                        <div><dt class="text-2xs font-semibold uppercase tracking-wider text-tx-soft">{{ $etiqueta }}</dt><dd class="text-tx">{{ $valor }}</dd></div>
                    @endforeach
                </dl>
            @endif
        </x-card>

        <x-card title="Último estatus administrativo registrado en LOC" icon="shield" description="Registro cronológicamente más reciente entre las vigencias con vínculo verificado.">
            @if ($mensaje)
                {!! $mensaje !!}
            @else
                <div data-situacion="determinada">
                    <x-status-badge :label="$u->estatus_credencial ?: 'Sin estatus'" :tone="$tonoEstatus[strtoupper((string) $u->estatus_credencial)] ?? 'neutral'"/>
                    <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                        <div><dt class="text-2xs font-semibold uppercase tracking-wider text-tx-soft">Periodo de credencial</dt><dd>{{ $u->periodo_credencial ?: '—' }}</dd></div>
                        <div><dt class="text-2xs font-semibold uppercase tracking-wider text-tx-soft">Fecha de solicitud</dt><dd>{!! $fecha($u->fecha_solicitud) ?? $noRegistrada !!}</dd></div>
                        @if ($u->fecha_baja || $u->numero_oficio_baja)
                            <div><dt class="text-2xs font-semibold uppercase tracking-wider text-tx-soft">Fecha de baja</dt><dd>{!! $fecha($u->fecha_baja) ?? $noRegistrada !!}</dd></div>
                            <div><dt class="text-2xs font-semibold uppercase tracking-wider text-tx-soft">Oficio de baja</dt><dd class="font-mono">{{ $u->numero_oficio_baja ?: '—' }}</dd></div>
                        @endif
                    </dl>
                    @if (filled($u->motivo_situacion_especial))
                        {{-- El motivo describe movimientos de armamento: su detalle se reserva para C3. --}}
                        <p class="mt-3 text-xs text-amber-900">Hay una situación especial registrada; su detalle se consulta con el armamento.</p>
                    @endif
                    <p class="mt-3 text-2xs text-tx-soft">La base no conserva fecha de vencimiento: el periodo es el declarado en el archivo LOC.</p>
                </div>
            @endif
        </x-card>
    </div>

    <section class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Relaciones del elemento">
        <x-kpi label="Vigencias con vínculo verificado" :value="number_format($datos['verificadas']->count())" icon="shield" tone="info"/>
        <x-kpi label="Registros sin vínculo verificado" :value="number_format($datos['sin_vinculo']->count())" hint="Mismo CUIP o persona sin conciliar" icon="alert" :tone="$datos['sin_vinculo']->isEmpty() ? 'neutral' : 'warning'"/>
        <x-kpi label="Trámites de credencial" :value="number_format($datos['tramites'])" icon="idcard" tone="info"/>
        <x-kpi label="Expediente biométrico" :value="$datos['biometrico'] ? 'Existe' : 'No localizado'" hint="Solo se indica su existencia" icon="fingerprint" tone="neutral"/>
    </section>

@elseif ($seccion === 'vigencias')
    @php $mensaje = $situacion($datos); $u = $datos['ultima']; $columnas = ['Credencial LOC', 'Estatus', 'Periodo', 'Fecha de solicitud', 'Fecha de baja', 'Oficio de baja']; @endphp
    @php
        $fila = fn ($v) => '<tr data-vigencia="'.$v->id.'"><td class="whitespace-nowrap font-mono text-xs">'.e($v->numero_credencial ?: 'Sin número').'</td><td class="text-xs">'.e($v->estatus_credencial ?: '—')
            .'</td><td class="text-xs">'.e($v->periodo_credencial ?: '—').'</td><td class="text-xs">'.($fecha($v->fecha_solicitud) ?? $noRegistrada).'</td><td class="text-xs">'.($fecha($v->fecha_baja) ?? $noRegistrada)
            .'</td><td class="font-mono text-xs">'.e($v->numero_oficio_baja ?: '—').'</td></tr>';
    @endphp
    <x-card title="Registro cronológicamente más reciente" icon="shield" description="Elegido con la regla de LocCredentialsPolicyService (fecha de baja, si no fecha de solicitud). No es una fecha de vencimiento.">
        @if ($mensaje)
            {!! $mensaje !!}
        @else
            <div class="table-wrap"><table class="table-data" data-ultima-vigencia>
                <thead><tr>@foreach ($columnas as $c)<th scope="col">{{ $c }}</th>@endforeach</tr></thead>
                <tbody>{!! $fila($u) !!}</tbody>
            </table></div>
        @endif
    </x-card>

    @php $anteriores = $datos['determinable'] ? $datos['anteriores'] : $datos['verificadas']; @endphp
    <x-card class="mt-5" :title="$datos['determinable'] ? 'Registros anteriores' : 'Registros con vínculo verificado'" icon="list" description="Del más reciente al más antiguo según la fecha registrada; los registros sin fecha van al final.">
        @if ($anteriores->isNotEmpty())
            <div class="table-wrap"><table class="table-data" data-vigencias-anteriores>
                <thead><tr>@foreach ($columnas as $c)<th scope="col">{{ $c }}</th>@endforeach</tr></thead>
                <tbody>@foreach ($anteriores as $v){!! $fila($v) !!}@endforeach</tbody>
            </table></div>
        @else
            <x-empty-table :columns="$columnas" :message="$datos['ultima'] ? 'No hay registros anteriores al más reciente.' : 'El elemento no tiene registros de vigencia con vínculo verificado.'"/>
        @endif
    </x-card>

    @if ($datos['sin_vinculo']->isNotEmpty())
        <x-card class="mt-5" title="Registros sin vínculo verificado con este elemento" icon="alert" description="Comparten el CUIP o la persona, pero no están conciliados con el padrón: no se confirma que pertenezcan a este elemento.">
            <div class="table-wrap"><table class="table-data" data-vigencias-sin-vinculo>
                <thead><tr>@foreach ($columnas as $c)<th scope="col">{{ $c }}</th>@endforeach</tr></thead>
                <tbody>@foreach ($datos['sin_vinculo'] as $v){!! $fila($v) !!}@endforeach</tbody>
            </table></div>
        </x-card>
    @endif

@elseif ($seccion === 'armamento-vinculado')
    <p class="mb-4 rounded-md border border-inst-200 bg-inst-50 p-3 text-xs leading-5 text-inst-900">
        Armas referidas en vigencias verificadas y en trámites de credencial de este elemento. Es una relación documental:
        el sistema no registra asignaciones de armas a personas.
    </p>
    @if ($datos['armas']->isNotEmpty())
        <x-card title="Armas referidas ({{ $datos['armas']->count() }})" icon="shield">
            <div class="table-wrap"><table class="table-data">
                <thead><tr><th scope="col">Matrícula</th><th scope="col">Tipo / clase</th><th scope="col">Marca · modelo · calibre</th><th scope="col">Estatus / situación</th><th scope="col">Fuente de la relación</th></tr></thead>
                <tbody>
                    @foreach ($datos['armas'] as $r)
                        @php $a = $r['arma']; @endphp
                        <tr data-arma>
                            <td class="whitespace-nowrap"><span class="block font-mono text-xs font-semibold">{{ $a->matricula }}</span><span class="block text-2xs text-tx-soft">ID_ARMAMENTO {{ $a->id_armamento ?: '—' }}</span></td>
                            <td class="text-xs">{{ $a->tipo_arma ?: '—' }} / {{ $a->clase_arma ?: '—' }}</td>
                            <td class="text-xs">{{ collect([$a->marca, $a->modelo, $a->calibre])->filter()->implode(' · ') ?: '—' }}</td>
                            <td class="whitespace-nowrap text-2xs">{{ $a->estatus ?: '—' }} / {{ $a->situacion ?: '—' }}</td>
                            <td class="text-2xs"><ul class="space-y-0.5">@foreach ($r['fuentes'] as $f)<li data-fuente>{{ $f }}</li>@endforeach</ul></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </x-card>
    @endif
    @if ($datos['sin_registro']->isNotEmpty())
        <x-card class="mt-5" title="Matrículas referidas sin registro de armamento" icon="alert" description="La matrícula aparece en el documento, pero no hay un arma registrada con ella.">
            <ul class="space-y-1 text-xs">
                @foreach ($datos['sin_registro'] as $r)
                    <li data-matricula-sin-registro><span class="font-mono font-semibold">{{ $r['matricula'] }}</span> — {{ $r['fuentes']->implode('; ') }}</li>
                @endforeach
            </ul>
        </x-card>
    @endif
    @if ($datos['armas']->isEmpty() && $datos['sin_registro']->isEmpty())
        <p class="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-tx-soft">Ninguna vigencia verificada ni trámite de este elemento refiere armamento.</p>
    @endif

@else
    @php
        $etapas = ['impresa' => ['Impresa', 'success'], 'pendiente_impresion' => ['Pendiente de impresión', 'process'], 'pendiente_biometrico' => ['Pendiente de biométricos', 'warning'],
            'sin_validacion_biometrica' => ['Sin validación biométrica', 'neutral'], 'sin_prefiltro' => ['Sin prefiltro', 'neutral'], 'descartado' => ['Descartado en prefiltro', 'danger']];
        $entregas = ['entregada' => 'Entregada', 'disponible' => 'Disponible para entrega', 'anomalia_multiple' => 'Varias impresiones efectivas (revisión)', 'no_emitida' => 'No emitida', 'sin_impresion' => 'Sin impresión efectiva'];
        $estados = ['recibida' => 'Recibida', 'evaluada' => 'Evaluada', 'enviada_impresion' => 'Enviada a impresión'];
    @endphp
    @if ($datos->isNotEmpty())
        <x-card title="Trámites de credencial ({{ $datos->count() }})" icon="idcard" description="Relacionados por el padrón (persona) o por CUIP exacto cuando el trámite no apunta a otra persona. Nunca por nombre.">
            <div class="table-wrap"><table class="table-data">
                <thead><tr><th scope="col">Tarjeta / oficio</th><th scope="col">Solicitud</th><th scope="col">Etapa del elemento</th><th scope="col">Credencial</th><th scope="col">Entrega</th><th scope="col"><span class="sr-only">Acciones</span></th></tr></thead>
                <tbody>
                    @foreach ($datos as $t)
                        @php $e = $t['elemento']; $s = $e->solicitud; [$etLabel, $etTono] = $etapas[$t['etapa']] ?? [$t['etapa'], 'neutral']; $c = $e->credencialEmitida; @endphp
                        <tr data-tramite="{{ $e->id }}">
                            <td class="whitespace-nowrap">
                                <span class="block font-mono text-xs font-semibold">{{ $s?->numero_tarjeta ?: 'Sin número asignado' }}</span>
                                <span class="block font-mono text-2xs text-tx-soft">{{ $s?->numero_oficio ?: '—' }}</span>
                                @if ($t['vinculo'] === 'cuip')
                                    <x-status-badge label="Coincidencia de CUIP sin vínculo de padrón" tone="warning" size="xs"/>
                                @endif
                            </td>
                            <td class="text-xs">
                                <span class="block">{{ $s?->area_solicitante ?: '—' }}</span>
                                <span class="block text-2xs text-tx-soft">{{ $estados[$s?->estado_general] ?? ($s?->estado_general ?: '—') }} · recibida {{ $fecha($s?->fecha_recepcion) ?? 'sin fecha' }}</span>
                            </td>
                            <td><x-status-badge :label="$etLabel" :tone="$etTono" size="xs"/><span class="mt-0.5 block text-2xs text-tx-soft">Prefiltro: {{ $e->resultado_prefiltro ?: 'pendiente' }}</span></td>
                            <td class="whitespace-nowrap text-xs">
                                @if ($c)
                                    <span class="block font-mono">{{ $c->folio ?: 'Sin folio' }}</span><span class="block text-2xs text-tx-soft">{{ ucfirst((string) $c->estado_emision) }}</span>
                                @else
                                    <span class="text-tx-soft">Sin credencial emitida</span>
                                @endif
                            </td>
                            <td class="text-xs">{{ $c ? ($entregas[$t['entrega']['estado']] ?? '—') : '—' }}</td>
                            <td class="whitespace-nowrap text-right">
                                @can('access-entregas')
                                    <a href="{{ route('entregas.tarjeta', $e->solicitud_id) }}" class="btn btn-secondary btn-sm" data-enlace-entregas>Ver en Entrega y Seguimiento</a>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </x-card>
    @else
        <p class="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-tx-soft">Este elemento no tiene trámites de credencialización registrados.</p>
    @endif
@endif
