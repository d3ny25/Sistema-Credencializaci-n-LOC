@extends('layouts.app')

@section('title', $detail ? 'Detalle de solicitud' : 'Credencialización')
@section('heading', $detail ? 'Detalle de solicitud' : 'Credencialización')
@section('subheading', $detail ? 'Expediente institucional de credencialización y elementos importados' : 'Solicitudes, carga de elementos y validación cruzada')

@section('content')
    @php
        $activeTab = $detail ? 'detalle' : 'solicitudes';
        $tabItems = [
            ['key' => 'solicitudes', 'label' => 'Expedientes de solicitud', 'route' => 'credencializacion'],
        ];
        if ($detail && $solicitud) {
            $tabItems[] = [
                'key' => 'detalle',
                'label' => 'Expediente: ' . $solicitud->numero_tarjeta,
                'route' => 'credencializacion.detalle',
                'parameters' => ['solicitud' => $solicitud->id],
            ];
        } elseif ($detail) {
            $tabItems[] = [
                'key' => 'detalle',
                'label' => 'Detalle de expediente',
                'route' => 'credencializacion.detalle',
                'parameters' => ['solicitud' => 'referencia'],
            ];
        }
    @endphp

    <x-module-tabs :active="$activeTab" :items="$tabItems"/>

    @if ($detail)
        @php
            // Etapa derivada solo para presentación; el flujo y sus reglas no cambian.
            $etapas = null;
            if ($solicitud) {
                $estado = $solicitud->estado_general;
                $conElementos = (int) $solicitud->total_elementos > 0;
                $evaluada = in_array($estado, ['evaluada', 'enviada_impresion'], true) || $solicitud->fecha_prefiltro !== null;
                $enviada = $estado === 'enviada_impresion';
                $actual = $enviada ? 5 : ($evaluada ? 4 : ($conElementos ? 3 : 2));
                $etapas = collect([
                    ['label' => 'Recepción', 'hint' => 'Oficio y tarjeta registrados'],
                    ['label' => 'Carga de elementos', 'hint' => $conElementos ? number_format((int) $solicitud->total_elementos).' filas importadas' : 'Pendiente de archivo Excel'],
                    ['label' => 'Prefiltro', 'hint' => $evaluada ? 'Validación cruzada ejecutada' : 'Validación cruzada pendiente'],
                    ['label' => 'Envío a impresión', 'hint' => $enviada ? 'Aptos enviados a la cola' : ($evaluada && (int) $solicitud->total_aptos === 0 ? 'Sin elementos aptos para enviar' : 'Aptos por enviar')],
                ])->map(fn ($etapa, $i) => $etapa + ['state' => $i + 1 < $actual ? 'done' : ($i + 1 === $actual ? 'current' : 'pending')])->all();
                [$estadoLabel, $estadoTono] = match ($estado) {
                    'enviada_impresion' => ['Enviada a impresión', 'success'],
                    'evaluada' => ['Evaluada', 'info'],
                    default => [ucfirst((string) $estado), 'warning'],
                };
            }
        @endphp

        <div class="panel mb-5">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-linea px-4 py-2.5">
                <div class="flex min-w-0 items-center gap-2">
                    <a href="{{ route('credencializacion') }}" class="btn btn-ghost btn-sm -ml-1">
                        <x-icon name="arrowRight" class="h-3.5 w-3.5 rotate-180"/>
                        Volver a solicitudes
                    </a>
                    @if ($solicitud)
                        <span class="text-slate-300" aria-hidden="true">/</span>
                        <span class="truncate font-mono text-xs font-medium text-tx-soft">Expediente {{ $solicitud->numero_tarjeta }}</span>
                    @endif
                </div>
                @if ($solicitud)
                    <div class="flex items-center gap-2">
                        <span class="code-chip">ID #{{ $solicitud->id }}</span>
                        <x-status-badge :label="$estadoLabel" :tone="$estadoTono"/>
                    </div>
                @endif
            </div>
            @if ($etapas)
                <div class="px-5 py-4">
                    <x-stage-stepper :steps="$etapas" label="Etapas de la solicitud"/>
                </div>
            @endif
        </div>

        @if ($solicitud)
            @include('modules.credencializacion.detalle-resumen')

            @if ($solicitud->total_elementos === 0)
                @include('modules.credencializacion.detalle-carga-elementos')
            @else
                @include('modules.credencializacion.detalle-elementos')
            @endif
        @else
            @include('modules.credencializacion.detalle-vacio')
        @endif
    @else
        {{-- Listado de solicitudes --}}
        @include('modules.credencializacion.listado-metricas')

        <div x-data="{ modalNuevaSolicitud: false }" x-init="$watch('modalNuevaSolicitud', value => { if (value) { document.body.classList.add('overflow-hidden'); } else { document.body.classList.remove('overflow-hidden'); } })">
            @include('modules.credencializacion.listado-tabla')
            @include('modules.credencializacion.modal-nueva-solicitud')
        </div>
    @endif
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Respaldo de interacción para el modal de nueva solicitud (compatible con Alpine y Vanilla JS)
        const btnNueva = document.getElementById('btn-nueva-solicitud');
        const modalNueva = document.getElementById('modal-nueva-solicitud');
        if (btnNueva && modalNueva) {
            btnNueva.addEventListener('click', function () {
                setTimeout(() => {
                    if (modalNueva.style.display === 'none' || modalNueva.hasAttribute('x-cloak')) {
                        modalNueva.removeAttribute('x-cloak');
                        modalNueva.style.display = 'flex';
                        document.body.classList.add('overflow-hidden');
                    }
                }, 50);
            });

            document.querySelectorAll('[data-close-modal="nueva-solicitud"]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    modalNueva.style.display = 'none';
                    document.body.classList.remove('overflow-hidden');
                });
            });
        }
    });
</script>
@endpush
