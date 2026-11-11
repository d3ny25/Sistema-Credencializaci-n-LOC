@extends('layouts.app')

@section('title', 'Panel principal')
@section('heading', 'Panel principal')
@section('subheading', 'Resumen del proceso de credencialización · LOC No. 139')

@section('content')
    <section class="inst-pattern overflow-hidden rounded-xl bg-inst-900 p-6 text-white shadow-card sm:p-8">
        <div class="max-w-3xl">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-inst-50/75">Sistema Estatal · LOC N.º 139</p>
            <h2 class="mt-3 text-2xl font-semibold sm:text-3xl">Panel institucional</h2>
            <p class="mt-3 max-w-2xl text-sm leading-6 text-inst-50/85">
                Vista general de las fuentes persistidas y de las etapas operativas del sistema. Los indicadores solo resumen registros existentes; no representan actividad ni pruebas de hardware.
            </p>
        </div>
    </section>

    <section class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Indicadores disponibles">
        @foreach ([
            ['label' => 'Personas en la LOC', 'value' => $metrics['people'], 'description' => 'Registros persistidos', 'icon' => 'users', 'icon_class' => 'bg-inst-50 text-estado-info'],
            ['label' => 'Solicitudes recibidas', 'value' => $metrics['requests'], 'description' => 'Solicitudes persistidas', 'icon' => 'inbox', 'icon_class' => 'bg-amber-50 text-estado-adv'],
            ['label' => 'Elementos en solicitudes', 'value' => $metrics['request_elements'], 'description' => 'Elementos persistidos', 'icon' => 'list', 'icon_class' => 'bg-emerald-50 text-estado-exito'],
            ['label' => 'Credenciales registradas', 'value' => $metrics['credentials'], 'description' => 'Incluye cualquier estado de emisión', 'icon' => 'creditcard', 'icon_class' => 'bg-slate-100 text-tx-soft'],
        ] as $metric)
            <article class="rounded-lg bg-white p-5 shadow-card ring-1 ring-slate-900/5 transition-shadow hover:shadow-md">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs font-medium uppercase tracking-wide text-tx-soft">{{ $metric['label'] }}</p>
                        <p class="mt-3 text-3xl font-semibold tracking-tight text-inst-900">{{ number_format($metric['value']) }}</p>
                    </div>
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $metric['icon_class'] }}">
                        <x-icon :name="$metric['icon']" class="h-5 w-5"/>
                    </span>
                </div>
                <p class="mt-3 text-xs leading-5 text-tx-soft">{{ $metric['description'] }}</p>
            </article>
        @endforeach
    </section>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <section class="xl:col-span-2">
            <x-card title="Avance del proceso" icon="list" description="Etapas visibles del flujo; no se infiere avance operativo hasta que existan registros y reglas autorizadas.">
                <ol class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ([
                        ['label' => 'Gestión de información', 'description' => 'Fuentes y cargas LOC', 'icon' => 'database'],
                        ['label' => 'Credencialización', 'description' => 'Solicitudes y validaciones', 'icon' => 'idcard'],
                        ['label' => 'Impresión', 'description' => 'Biometría e impresión', 'icon' => 'printer'],
                        ['label' => 'Entrega', 'description' => 'Recepción y entrega física', 'icon' => 'truck'],
                        ['label' => 'Validación LOC', 'description' => 'Conciliación y seguimiento', 'icon' => 'search'],
                    ] as $stage)
                        <li class="rounded-lg border border-slate-200 bg-slate-50/70 p-4 transition-colors hover:border-inst-700/30 hover:bg-inst-50/40">
                            <div class="flex items-start gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-inst-700 shadow-sm ring-1 ring-slate-900/5">
                                    <x-icon :name="$stage['icon']" class="h-4 w-4"/>
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-inst-900">{{ $stage['label'] }}</p>
                                    <p class="mt-1 text-xs leading-5 text-tx-soft">{{ $stage['description'] }}</p>
                                </div>
                            </div>
                            <div class="mt-4 border-t border-slate-200/80 pt-3">
                                <x-status-badge label="Sin estado operativo" tone="warning"/>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </x-card>
        </section>
        <x-card title="Avisos y pendientes" icon="bell" description="No hay un servicio de avisos o tareas conectado.">
            <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50/60 p-5 text-center">
                <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-white text-tx-soft shadow-sm ring-1 ring-slate-900/5">
                    <x-icon name="bell" class="h-5 w-5"/>
                </span>
                <p class="mt-3 text-sm font-semibold text-inst-900">Sin avisos disponibles</p>
                <p class="mt-1 text-xs leading-5 text-tx-soft">No se inventan pendientes ni confirmaciones.</p>
                <a href="{{ route('avisos') }}" class="mt-4 inline-flex items-center gap-2 rounded-md px-2 py-1 text-sm font-medium text-inst-700 transition-colors hover:bg-inst-50 hover:text-inst-900 focus:outline-none focus:ring-2 focus:ring-inst-700 focus:ring-offset-1">
                    Consultar avisos institucionales
                    <x-icon name="arrowRight" class="h-4 w-4"/>
                </a>
            </div>
        </x-card>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-card title="Actividad reciente" icon="clock" description="El sistema aún no tiene una bitácora transversal conectada.">
            <x-empty-table :columns="['Fecha', 'Área', 'Evento', 'Responsable']" message="Sin actividad disponible; no se muestra contenido de demostración."/>
        </x-card>
        <x-card title="Entorno y disponibilidad" icon="info" description="Los módulos mantienen sus estados vacíos hasta que las operaciones reales estén autorizadas.">
            <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach ([
                    ['label' => 'Fuentes LOC', 'status' => 'Persistencia local', 'tone' => 'info', 'icon' => 'database'],
                    ['label' => 'Autenticación', 'status' => 'No configurada', 'tone' => 'warning', 'icon' => 'shield'],
                    ['label' => 'Impresión física', 'status' => 'No verificada', 'tone' => 'neutral', 'icon' => 'printer'],
                    ['label' => 'Biometría', 'status' => 'No conectada', 'tone' => 'neutral', 'icon' => 'fingerprint'],
                ] as $availability)
                    <div class="flex min-w-0 items-center gap-3 rounded-lg border border-slate-100 bg-slate-50/50 p-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-tx-soft shadow-sm ring-1 ring-slate-900/5">
                            <x-icon :name="$availability['icon']" class="h-4 w-4"/>
                        </span>
                        <div class="min-w-0">
                            <dt class="text-xs font-medium text-tx-soft">{{ $availability['label'] }}</dt>
                            <dd class="mt-1"><x-status-badge :label="$availability['status']" :tone="$availability['tone']"/></dd>
                        </div>
                    </div>
                @endforeach
            </dl>
        </x-card>
    </div>
@endsection
