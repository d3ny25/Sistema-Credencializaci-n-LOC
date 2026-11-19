@php
    $titulos = ['elementos' => 'Elementos del padrón', 'armamento' => 'Armamento', 'tarjetas' => 'Tarjetas', 'folios' => 'Credenciales emitidas (folio)', 'vigencias' => 'Credenciales LOC'];
    $estados = ['recibida' => ['Recibida', 'neutral'], 'evaluada' => ['Evaluada', 'info'], 'enviada_impresion' => ['Enviada a impresión', 'process']];
    $conResultados = $resultados === null ? [] : array_filter($resultados, fn ($p) => $p->total() > 0);
    $sinResultados = $resultados === null ? [] : array_keys(array_diff_key($resultados, $conResultados));
@endphp

<x-card class="mb-5">
    <form method="GET" action="{{ route('consultas') }}" role="search" aria-label="Buscar en Consultas">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-[minmax(0,1fr)_12rem_auto] md:items-end">
            <label class="field-label">
                Identificador o nombre
                <input type="search" name="q" value="{{ $q ?? old('q') }}" maxlength="100" required autocomplete="off" autofocus
                       placeholder="CUIP, CURP, RFC, nombre, matrícula, tarjeta, oficio o folio">
            </label>
            <label class="field-label">
                Buscar en
                <select name="tipo">
                    @foreach (\App\Services\ConsultaBusquedaService::CONTEXTOS as $clave => $etiqueta)
                        <option value="{{ $clave }}" @selected($contexto === $clave)>{{ $etiqueta }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" class="btn btn-primary"><x-icon name="search" class="h-4 w-4"/> Buscar</button>
        </div>
        <p class="mt-2 text-2xs leading-5 text-tx-soft">
            Elementos: CUIP, CURP, RFC, ID_PERSONAL o nombre (todas las palabras). Armamento: matrícula o ID_ARMAMENTO.
            Tarjetas: número de tarjeta u oficio, folio de credencial o número de credencial LOC.
        </p>
        @if ($detectado)
            <p class="mt-2" data-detectado><x-status-badge :label="'Identificador detectado: '.$detectado" tone="info" size="xs"/>
                <span class="ml-1 text-2xs text-tx-soft">Orientativo: se busca en todos los campos del contexto elegido.</span></p>
        @endif
    </form>
</x-card>

@if ($resultados === null)
    <p class="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-tx-soft" data-sin-busqueda>
        Escriba un identificador o un nombre para comenzar. Los resultados se agrupan por tipo de registro.
    </p>
@elseif ($conResultados === [])
    <p class="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-tx" data-sin-resultados>
        No hay coincidencias para «{{ $q }}» en {{ $contexto === 'todo' ? 'ningún contexto' : mb_strtolower(\App\Services\ConsultaBusquedaService::CONTEXTOS[$contexto]) }}.
    </p>
@else
    @if ($sinResultados !== [] && $contexto === 'todo')
        <p class="mb-3 text-xs text-tx-soft">Sin coincidencias en: {{ implode(', ', array_map(fn ($g) => $titulos[$g], $sinResultados)) }}.</p>
    @endif

    <div class="space-y-5">
        @foreach ($conResultados as $grupo => $paginas)
            <x-card :title="$titulos[$grupo].' ('.number_format($paginas->total()).')'" data-grupo="{{ $grupo }}">
                <div class="table-wrap">
                    <table class="table-data">
                        <caption class="sr-only">{{ $titulos[$grupo] }} que coinciden con la búsqueda</caption>
                        @if ($grupo === 'elementos')
                            <thead><tr><th scope="col">CUIP</th><th scope="col">Nombre</th><th scope="col">Región / municipio</th><th scope="col">Estatus en el padrón</th></tr></thead>
                            <tbody>
                                @foreach ($paginas as $p)
                                    <tr>
                                        <td class="whitespace-nowrap font-mono text-xs font-semibold">
                                            <a href="{{ route('consultas.elemento', $p->id) }}" class="text-inst-800 hover:text-inst-900 hover:underline" data-expediente-elemento>{{ $p->cuip }}</a>
                                        </td>
                                        <td class="text-xs">{{ $p->nombre }}</td>
                                        <td class="text-xs text-tx-soft">{{ $p->region ?: '—' }} · {{ $p->municipio ?: '—' }}</td>
                                        <td class="text-2xs">{{ $p->estatus ?: 'Sin estatus registrado' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        @elseif ($grupo === 'armamento')
                            <thead><tr><th scope="col">Matrícula</th><th scope="col">Tipo / clase</th><th scope="col">Marca · modelo · calibre</th><th scope="col">Estatus / situación</th></tr></thead>
                            <tbody>
                                @foreach ($paginas as $a)
                                    <tr>
                                        <td class="whitespace-nowrap">
                                            <span class="block font-mono text-xs font-semibold">{{ $a->matricula }}</span>
                                            <span class="block text-2xs text-tx-soft">ID_ARMAMENTO {{ $a->id_armamento ?: '—' }}</span>
                                        </td>
                                        <td class="text-xs">{{ $a->tipo_arma ?: '—' }} / {{ $a->clase_arma ?: '—' }}</td>
                                        <td class="text-xs">{{ collect([$a->marca, $a->modelo, $a->calibre])->filter()->implode(' · ') ?: '—' }}</td>
                                        <td class="whitespace-nowrap text-2xs">{{ $a->estatus ?: '—' }} / {{ $a->situacion ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        @elseif ($grupo === 'tarjetas')
                            <thead><tr><th scope="col">Tarjeta</th><th scope="col">Oficio</th><th scope="col">Área solicitante</th><th scope="col" class="text-right">Elementos</th><th scope="col">Estado</th><th scope="col"><span class="sr-only">Acciones</span></th></tr></thead>
                            <tbody>
                                @foreach ($paginas as $t)
                                    @php [$estadoLabel, $estadoTono] = $estados[$t->estado_general] ?? [$t->estado_general ?: 'Sin estado', 'neutral']; @endphp
                                    <tr>
                                        <td class="whitespace-nowrap font-mono text-xs font-semibold">{{ $t->numero_tarjeta ?: 'Sin número asignado' }}</td>
                                        <td class="whitespace-nowrap font-mono text-xs">{{ $t->numero_oficio ?: '—' }}</td>
                                        <td class="max-w-[16rem] truncate text-xs" title="{{ $t->area_solicitante }}">{{ $t->area_solicitante ?: '—' }}</td>
                                        <td class="text-right text-xs tabular-nums">{{ $t->elementos_count }}</td>
                                        <td><x-status-badge :label="$estadoLabel" :tone="$estadoTono" size="xs"/></td>
                                        <td class="whitespace-nowrap text-right">
                                            @can('access-entregas')
                                                <a href="{{ route('entregas.tarjeta', $t->id) }}" class="btn btn-secondary btn-sm">Ver en Entrega y Seguimiento</a>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        @elseif ($grupo === 'folios')
                            <thead><tr><th scope="col">Folio</th><th scope="col">Estado de emisión</th><th scope="col">Tarjeta</th><th scope="col">Elemento</th></tr></thead>
                            <tbody>
                                @foreach ($paginas as $c)
                                    <tr>
                                        <td class="whitespace-nowrap font-mono text-xs font-semibold">{{ $c->folio }}</td>
                                        <td class="text-xs">{{ ucfirst((string) $c->estado_emision) }}</td>
                                        <td class="whitespace-nowrap font-mono text-xs">{{ $c->solicitud?->numero_tarjeta ?: '—' }}</td>
                                        <td class="text-xs">
                                            <span class="block font-mono">{{ $c->solicitudElemento?->cuip ?: 'Sin CUIP' }}</span>
                                            <span class="block text-2xs text-tx-soft">{{ $c->solicitudElemento?->persona?->nombre ?: 'Sin vínculo con el padrón' }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        @else
                            <thead><tr><th scope="col">Credencial LOC</th><th scope="col">Estatus</th><th scope="col">Periodo</th><th scope="col">Elemento</th></tr></thead>
                            <tbody>
                                @foreach ($paginas as $v)
                                    <tr>
                                        <td class="whitespace-nowrap font-mono text-xs font-semibold">{{ $v->numero_credencial }}</td>
                                        <td class="text-xs">{{ $v->estatus_credencial ?: '—' }}</td>
                                        {{-- Sin fecha de vencimiento en la base: solo el periodo declarado en el archivo LOC. --}}
                                        <td class="text-xs">{{ $v->periodo_credencial ?: '—' }}</td>
                                        <td class="text-xs">
                                            <span class="block font-mono">{{ $v->cuip ?: 'Sin CUIP' }}</span>
                                            @if (\App\Services\ConsultaBusquedaService::vinculoVerificado($v))
                                                <span class="block text-2xs text-tx-soft">{{ $v->persona?->nombre }}</span>
                                            @else
                                                <x-status-badge label="Sin vínculo verificado con el padrón" tone="warning" size="xs"/>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        @endif
                    </table>
                </div>
                @if ($paginas->hasPages())
                    <div class="mt-4">{{ $paginas->links() }}</div>
                @endif
            </x-card>
        @endforeach
    </div>
@endif
