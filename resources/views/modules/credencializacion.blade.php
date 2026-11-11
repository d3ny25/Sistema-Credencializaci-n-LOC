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
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200/90 bg-white px-3.5 py-2 shadow-2xs">
            <div class="flex items-center gap-2">
                <a href="{{ route('credencializacion') }}" class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold text-inst-700 hover:bg-inst-50 hover:text-inst-900 transition-colors">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    <span>Volver a solicitudes</span>
                </a>
                @if ($solicitud)
                    <span class="text-slate-300">/</span>
                    <span class="text-xs font-mono font-medium text-slate-500">Expediente {{ $solicitud->numero_tarjeta }}</span>
                @endif
            </div>
            @if ($solicitud)
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center gap-1 rounded bg-slate-100 px-2 py-0.5 text-[11px] font-mono font-semibold text-slate-600 border border-slate-200/70">
                        ID #{{ $solicitud->id }}
                    </span>
                    <x-status-badge :label="ucfirst($solicitud->estado_general)" :tone="$solicitud->estado_general === 'enviada_impresion' ? 'success' : ($solicitud->estado_general === 'evaluada' ? 'info' : 'warning')"/>
                </div>
            @endif
        </div>

        @if (session('status'))
            <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-5 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900">
                <p class="font-semibold">Se presentaron los siguientes inconvenientes:</p>
                <ul class="mt-1 list-disc list-inside space-y-0.5 text-xs text-rose-800">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

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
