@extends('modules.entrega.layout')

@section('title', 'Entrega y seguimiento')
@section('heading', 'Entrega y seguimiento de credenciales')
@section('subheading', 'Localice un número de tarjeta para consultar el avance de sus elementos desde la credencialización hasta la entrega.')

@section('entrega')
    {{-- Solo cifras con fuente persistida: las de entrega y retención llegarán con sus registros. --}}
    <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-kpi label="Tarjetas registradas" :value="number_format($indicadores['tarjetas'])" hint="Solicitudes registradas en Credencialización" icon="idcard" tone="info"/>
        <x-kpi label="Enviadas a impresión" :value="number_format($indicadores['enviadas_impresion'])" hint="Autorizadas tras el prefiltro" icon="printer" tone="success"/>
        <x-kpi label="Elementos pendientes de biométricos" :value="number_format($indicadores['pendientes_biometrico'])" hint="Aptos sin validación 4/4 completa" icon="fingerprint" tone="warning"/>
    </div>

    <x-card title="Números de tarjeta" icon="search" description="Seleccione una tarjeta para abrir su expediente de seguimiento.">
        <form method="GET" action="{{ route('entregas') }}" class="panel-muted mb-5 p-4" role="search" aria-label="Buscar número de tarjeta">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto]">
                <label class="field-label">
                    Número de tarjeta
                    <input type="search" name="q" value="{{ $filtros['q'] ?? '' }}" maxlength="100" placeholder="Ej. TJ-2026-0001" autocomplete="off">
                </label>
                <label class="field-label">
                    Estado de la solicitud
                    <select name="estado">
                        <option value="">Todos los estados</option>
                        <option value="recibida" @selected(($filtros['estado'] ?? '') === 'recibida')>Recibida</option>
                        <option value="evaluada" @selected(($filtros['estado'] ?? '') === 'evaluada')>Evaluada</option>
                        <option value="enviada_impresion" @selected(($filtros['estado'] ?? '') === 'enviada_impresion')>Enviada a impresión</option>
                    </select>
                </label>
                <div class="flex items-end gap-2">
                    <button type="submit" class="btn btn-primary flex-1">Buscar</button>
                    <a href="{{ route('entregas') }}" class="btn btn-secondary">Limpiar</a>
                </div>
            </div>
        </form>

        @if ($tarjetas->isNotEmpty())
            <div class="table-wrap">
                <table class="table-data">
                    <caption class="sr-only">Números de tarjeta y avance de sus elementos</caption>
                    <thead>
                        <tr>
                            <th scope="col">Tarjeta</th>
                            <th scope="col">Área solicitante</th>
                            <th scope="col" class="text-right">Elementos</th>
                            <th scope="col" class="text-right">Impresas</th>
                            <th scope="col" class="text-right">Pend. biométricos</th>
                            <th scope="col">Estado</th>
                            <th scope="col"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tarjetas as $t)
                            @php
                                $r = $t->resumen;
                                [$estadoLabel, $estadoTono] = $seguimiento->estadoDerivado($t, $r);
                            @endphp
                            <tr>
                                <td class="whitespace-nowrap">
                                    <a href="{{ route('entregas.tarjeta', $t->id) }}" class="block font-mono text-xs font-semibold text-inst-800 hover:text-inst-900 hover:underline">{{ $t->numero_tarjeta ?: 'Sin número asignado' }}</a>
                                    <span class="block text-2xs text-tx-soft">Registrada {{ $t->created_at?->format('d/m/Y') }}</span>
                                </td>
                                <td class="max-w-[16rem]"><span class="block truncate text-xs" title="{{ $t->area_solicitante }}">{{ $t->area_solicitante ?: '—' }}</span></td>
                                <td class="text-right text-xs font-semibold tabular-nums text-inst-900">{{ number_format($r['total']) }}</td>
                                <td class="text-right text-xs font-semibold tabular-nums text-emerald-700" title="Elementos distintos con impresión vigente">{{ number_format($r['impresas']) }}</td>
                                <td class="text-right text-xs font-semibold tabular-nums {{ $r['pendientes_biometrico'] > 0 ? 'text-amber-800' : 'text-tx-soft' }}">{{ number_format($r['pendientes_biometrico']) }}</td>
                                <td class="whitespace-nowrap"><x-status-badge :label="$estadoLabel" :tone="$estadoTono" size="xs"/></td>
                                <td class="whitespace-nowrap text-right">
                                    <a href="{{ route('entregas.tarjeta', $t->id) }}" class="btn btn-secondary btn-sm" aria-label="Consultar seguimiento de la tarjeta {{ $t->numero_tarjeta }}">
                                        Consultar seguimiento <x-icon name="chevronRight" class="h-3.5 w-3.5"/>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($tarjetas->hasPages())
                <div class="mt-4">{{ $tarjetas->links() }}</div>
            @endif
        @else
            <x-empty-table :columns="['Tarjeta', 'Área solicitante', 'Elementos', 'Impresas', 'Pend. biométricos', 'Estado', 'Acciones']"
                           :message="filled($filtros['q'] ?? null) || filled($filtros['estado'] ?? null) ? 'Ningún número de tarjeta coincide con la búsqueda.' : 'Aún no hay tarjetas registradas en Credencialización.'"/>
        @endif
    </x-card>
@endsection
