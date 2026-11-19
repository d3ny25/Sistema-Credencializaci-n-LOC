@extends('layouts.app')

@section('title', 'Panel principal')
@section('heading', 'Panel principal')
@section('subheading', 'Estado operativo del proceso de credencialización · LOC No. 139')

@php
    $user = auth()->user();
    $puedeCredencializar = $user?->can('access-credencializacion');
    $puedeImprimir = $user?->can('access-print-queue');
    $puedeGestionar = $user?->can('access-administration');

    $indicadores = [
        ['label' => 'Solicitudes recibidas', 'value' => $metrics['requests'], 'description' => 'Solicitudes persistidas', 'icon' => 'inbox', 'tone' => 'info', 'href' => $puedeCredencializar ? route('credencializacion') : null],
        ['label' => 'Elementos en validación', 'value' => $metrics['en_validacion'], 'description' => 'Sin resultado de prefiltro', 'icon' => 'clock', 'tone' => 'warning'],
        ['label' => 'Aptos para impresión', 'value' => $metrics['aptos'], 'description' => 'Resultado de prefiltro apto', 'icon' => 'check', 'tone' => 'success'],
        ['label' => 'Descartados', 'value' => $metrics['descartados'], 'description' => 'Resultado de prefiltro descartado', 'icon' => 'alert', 'tone' => 'danger'],
        ['label' => 'Pendientes de biometría', 'value' => $metrics['pendientes_biometrico'], 'description' => 'Trámite en espera de biometría', 'icon' => 'fingerprint', 'tone' => 'warning', 'href' => $puedeImprimir ? route('impresion') : null],
        ['label' => 'Listos para impresión', 'value' => $metrics['listos_impresion'], 'description' => 'Trámite listo para imprimir', 'icon' => 'printer', 'tone' => 'warning', 'href' => $puedeImprimir ? route('impresion') : null],
        ['label' => 'Credenciales registradas', 'value' => $metrics['credentials'], 'description' => 'Incluye cualquier estado de emisión', 'icon' => 'creditcard', 'tone' => 'info'],
        ['label' => 'Personas en la LOC', 'value' => $metrics['people'], 'description' => 'Registros persistidos', 'icon' => 'users', 'tone' => 'neutral', 'href' => $puedeGestionar ? route('gestion') : null],
    ];

    $estadoSolicitud = fn (?string $estado): array => match ($estado) {
        'enviada_impresion' => ['Enviada a impresión', 'success'],
        'evaluada' => ['Evaluada', 'info'],
        'recibida' => ['Recibida', 'warning'],
        default => [str((string) $estado)->replace('_', ' ')->ucfirst()->toString() ?: 'Sin estado', 'neutral'],
    };
    $estadoImportacion = fn (?string $estado): string => match ($estado) {
        'completado' => 'success',
        'en_revision', 'fallido_parcial' => 'warning',
        'fallido' => 'danger',
        'pendiente', 'procesando' => 'info',
        default => 'neutral',
    };
    $fuente = fn (?string $tipo): string => match ($tipo) {
        'personal_autorizado_loc' => 'Personal LOC',
        'armamento_loc' => 'Armamento LOC',
        'vigencias_credenciales' => 'Vigencias',
        'catalogo_organizacional' => 'Catálogo organizacional',
        default => str((string) $tipo)->replace('_', ' ')->ucfirst()->toString(),
    };

    $accesos = array_values(array_filter([
        $puedeCredencializar ? ['label' => 'Solicitudes de credencialización', 'hint' => 'Registrar, cargar y evaluar elementos', 'icon' => 'idcard', 'href' => route('credencializacion')] : null,
        $puedeImprimir ? ['label' => 'Cola de impresión', 'hint' => 'Validar biometría, previsualizar y emitir', 'icon' => 'printer', 'href' => route('impresion')] : null,
        $puedeGestionar ? ['label' => 'Fuentes de información', 'hint' => 'Padrón, armamento, vigencias y catálogo', 'icon' => 'database', 'href' => route('gestion')] : null,
        auth()->user()?->can('access-consultas') ? ['label' => 'Consulta general', 'hint' => 'Buscar expedientes del personal', 'icon' => 'search', 'href' => route('consultas')] : null,
        ['label' => 'Avisos institucionales', 'hint' => 'Comunicados y pendientes', 'icon' => 'bell', 'href' => route('avisos')],
    ]));
@endphp

@section('page-actions')
    @if ($puedeCredencializar)
        <a href="{{ route('credencializacion') }}" class="btn btn-secondary">
            <x-icon name="idcard" class="h-4 w-4"/> Solicitudes
        </a>
    @endif
    @if ($puedeImprimir)
        <a href="{{ route('impresion') }}" class="btn btn-primary">
            <x-icon name="printer" class="h-4 w-4"/> Cola de impresión
        </a>
    @endif
@endsection

@section('content')
    {{-- 1. Indicadores: diseño de tarjeta del prototipo original (assets/js/ui.js · UI.kpi) --}}
    <section class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4" aria-label="Indicadores del flujo">
        @foreach ($indicadores as $metric)
            <x-kpi :label="$metric['label']" :value="number_format($metric['value'])" :hint="$metric['description']"
                   :icon="$metric['icon']" :tone="$metric['tone']" :href="$metric['href'] ?? null"/>
        @endforeach
    </section>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        {{-- 2. Solicitudes recientes (datos reales) --}}
        <x-card class="xl:col-span-2" title="Solicitudes recientes" icon="inbox" description="Últimas tarjetas de trabajo registradas en credencialización.">
            @if ($puedeCredencializar)
                <x-slot:actions>
                    <a href="{{ route('credencializacion') }}" class="btn btn-ghost btn-sm">Ver todas <x-icon name="arrowRight" class="h-3.5 w-3.5"/></a>
                </x-slot:actions>
            @endif
            @if ($recentRequests->isEmpty())
                <div class="flex flex-1 flex-col items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50/60 px-6 py-10 text-center">
                    <x-icon name="inbox" class="h-6 w-6 text-slate-400"/>
                    <p class="mt-2 text-sm font-semibold text-inst-900">Aún no hay solicitudes registradas</p>
                    <p class="mt-1 text-xs text-tx-soft">Las solicitudes aparecerán aquí al registrarse en credencialización.</p>
                </div>
            @else
                <div class="-mx-5 -mb-5 overflow-x-auto">
                    <table class="table-data table-compact">
                        <thead>
                            <tr>
                                <th scope="col">Tarjeta</th>
                                                                <th scope="col">Área solicitante</th>
                                <th scope="col" class="text-right">Aptos</th>
                                <th scope="col">Estado</th>
                                <th scope="col">Registro</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentRequests as $solicitud)
                                @php [$estadoLabel, $estadoTono] = $estadoSolicitud($solicitud->estado_general); @endphp
                                <tr>
                                    <td class="whitespace-nowrap">
                                        @if ($puedeCredencializar)
                                            <a href="{{ route('credencializacion.detalle', $solicitud->id) }}" class="font-mono text-xs font-semibold text-inst-700 hover:text-inst-900 hover:underline">{{ $solicitud->numero_tarjeta ?? '—' }}</a>
                                        @else
                                            <span class="font-mono text-xs font-semibold">{{ $solicitud->numero_tarjeta ?? '—' }}</span>
                                        @endif
                                    </td>
                                    <td class="max-w-[15rem]"><span class="block truncate" title="{{ $solicitud->area_solicitante }}">{{ $solicitud->area_solicitante ?? '—' }}</span><span class="block truncate text-2xs text-tx-soft">Oficio {{ $solicitud->numero_oficio ?? '—' }}</span></td>
                                    <td class="whitespace-nowrap text-right text-xs tabular-nums">{{ number_format((int) $solicitud->total_aptos) }} / {{ number_format((int) $solicitud->total_elementos) }}</td>
                                    <td class="whitespace-nowrap"><x-status-badge :label="$estadoLabel" :tone="$estadoTono" size="xs"/></td>
                                    <td class="whitespace-nowrap text-xs text-tx-soft">{{ $solicitud->created_at?->format('d/m/Y') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>

        {{-- 3. Avance del proceso (cálculo existente del controlador) --}}
        <x-card title="Avance del proceso" icon="chart" description="Proporciones sobre los elementos de solicitud persistidos.">
            <div class="flex flex-1 flex-col justify-evenly gap-5">
                @foreach ($progress as $stage)
                    <div>
                        <div class="mb-1.5 flex items-baseline justify-between gap-3">
                            <span class="text-sm font-medium text-tx">{{ $stage['label'] }}</span>
                            <span class="shrink-0 text-sm font-semibold tabular-nums text-inst-900">{{ $stage['pct'] }}%</span>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-label="{{ $stage['label'] }}" aria-valuenow="{{ $stage['pct'] }}" aria-valuemin="0" aria-valuemax="100" aria-valuetext="{{ $stage['pct'] }}% · {{ $stage['detail'] }}">
                            <div class="h-full rounded-full {{ $stage['bar'] }}" style="width: {{ $stage['pct'] }}%"></div>
                        </div>
                        <p class="mt-1 text-2xs text-tx-soft">{{ $stage['detail'] }}</p>
                    </div>
                @endforeach
            </div>
        </x-card>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        {{-- 4. Accesos según los permisos del usuario --}}
        <x-card title="Accesos directos" icon="layers">
            <ul class="-mx-2 -my-1 space-y-0.5">
                @foreach ($accesos as $acceso)
                    <li>
                        <a href="{{ $acceso['href'] }}" class="group flex items-center gap-3 rounded-lg px-2 py-2 transition-colors hover:bg-inst-50">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-tx-soft transition-colors group-hover:bg-white group-hover:text-inst-700">
                                <x-icon :name="$acceso['icon']" class="h-4 w-4"/>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-tx group-hover:text-inst-900">{{ $acceso['label'] }}</span>
                                <span class="block truncate text-2xs text-tx-soft">{{ $acceso['hint'] }}</span>
                            </span>
                            <x-icon name="chevronRight" class="h-4 w-4 shrink-0 text-slate-300 group-hover:text-inst-700"/>
                        </a>
                    </li>
                @endforeach
            </ul>
        </x-card>

        {{-- 5. Importaciones recientes (lotes persistidos) --}}
        <x-card title="Importaciones recientes" icon="upload" description="Lotes de fuentes de información procesados.">
            @if ($puedeGestionar)
                <x-slot:actions>
                    <a href="{{ route('gestion.historial') }}" class="btn btn-ghost btn-sm">Historial</a>
                </x-slot:actions>
            @endif
            @forelse ($recentImports as $lote)
                <div class="flex items-start gap-3 py-2.5 {{ $loop->first ? 'pt-0' : 'border-t border-slate-100' }}">
                    <x-icon name="file" class="mt-0.5 h-4 w-4 shrink-0 text-slate-400"/>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-tx" title="{{ $lote->nombre_archivo }}">{{ $fuente($lote->tipo_fuente) }}</p>
                        <p class="truncate text-2xs text-tx-soft">{{ ($lote->fecha_procesamiento ?? $lote->created_at)?->format('d/m/Y H:i') ?? 'Sin fecha' }}</p>
                    </div>
                    <x-status-badge :label="str((string) $lote->estado)->replace('_', ' ')->ucfirst()" :tone="$estadoImportacion($lote->estado)" size="xs"/>
                </div>
            @empty
                <p class="py-6 text-center text-sm text-tx-soft">Aún no hay importaciones registradas.</p>
            @endforelse
        </x-card>

        {{-- 6. Dispositivos registrados: solo conteos administrativos, nunca estado de conexión supuesto --}}
        <x-card title="Dispositivos de la estación" icon="device" description="Registro administrativo de periféricos. No implica conexión verificada.">
            @if ($user?->can('view-devices'))
                <x-slot:actions>
                    <a href="{{ route('administracion.dispositivos') }}" class="btn btn-ghost btn-sm">Gestionar</a>
                </x-slot:actions>
            @endif
            <dl class="grid grid-cols-3 gap-3 text-center">
                <div class="panel-muted px-2 py-3">
                    <dt class="text-2xs text-tx-soft">Registrados</dt>
                    <dd class="mt-1 text-xl font-semibold tabular-nums text-inst-900">{{ number_format($devices['total'] ?? 0) }}</dd>
                </div>
                <div class="panel-muted px-2 py-3">
                    <dt class="text-2xs text-tx-soft">Habilitados</dt>
                    <dd class="mt-1 text-xl font-semibold tabular-nums text-inst-900">{{ number_format($devices['habilitados'] ?? 0) }}</dd>
                </div>
                <div class="panel-muted px-2 py-3">
                    <dt class="text-2xs text-tx-soft">Sin prueba</dt>
                    <dd class="mt-1 text-xl font-semibold tabular-nums text-inst-900">{{ number_format($devices['sin_prueba'] ?? 0) }}</dd>
                </div>
            </dl>
            <p class="mt-4 text-xs leading-5 text-tx-soft">
                La captura con lector Cross Match y la impresión física se habilitan cuando su integración esté verificada en la estación.
            </p>
        </x-card>
    </div>
@endsection
