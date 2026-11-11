@extends('layouts.app')

@section('title', 'Catálogo Organizacional Vigente')
@section('heading', 'Gestión de información')
@section('subheading', 'Catálogo organizacional · Versión vigente oficial')

@section('content')
<div>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-sm font-medium text-tx-soft">Gestión de información / Catálogo organizacional</p>
            <div class="mt-1 flex flex-wrap items-center gap-3">
                <h2 class="text-xl font-semibold tracking-tight text-inst-900">Catálogo Organizacional Vigente</h2>
                @if ($active_version)
                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">
                        Versión Oficial v{{ $active_version->version_numero }}
                    </span>
                @else
                    <x-status-badge label="Sin versión activa" tone="neutral"/>
                @endif
            </div>
            @if ($active_version)
                <p class="mt-2 text-sm text-tx">
                    Activado el {{ $active_version->fecha_activacion?->format('d/m/Y H:i') ?? '—' }} · Archivo: {{ $active_version->archivo_nombre }} (Lote #{{ $active_version->import_batch_id }})
                </p>
            @endif
        </div>
        <a href="{{ route('gestion') }}" class="inline-flex shrink-0 items-center justify-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-inst-900 hover:bg-slate-50">
            Volver a fuentes
        </a>
    </div>

    <x-card title="Registros de la versión activa" description="Claves y adscripciones institucionales publicadas y autorizadas.">
        <form method="GET" action="{{ route('gestion.catalogo.vigente') }}" class="mb-4 flex flex-col gap-3 sm:flex-row">
            <input type="text" name="buscar" value="{{ $search }}" placeholder="Buscar por clave, agrupamiento, municipio o región..." class="block w-full max-w-md rounded-md border-slate-300 text-sm">
            <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                Buscar
            </button>
            @if ($search !== '')
                <a href="{{ route('gestion.catalogo.vigente') }}" class="inline-flex items-center rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-slate-100">
                    Limpiar
                </a>
            @endif
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-tx-soft">
                    <tr>
                        <th class="px-3 py-2.5">CLAVE</th>
                        <th class="px-3 py-2.5">Agrupamiento</th>
                        <th class="px-3 py-2.5">Municipio</th>
                        <th class="px-3 py-2.5">Región</th>
                        <th class="px-3 py-2.5">Subdirección</th>
                        <th class="px-3 py-2.5">Coordinación</th>
                        <th class="px-3 py-2.5">Dirección General</th>
                        <th class="px-3 py-2.5">Zona Militar</th>
                        <th class="px-3 py-2.5">Batallón</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($records as $item)
                        <tr class="hover:bg-slate-50/80">
                            <td class="px-3 py-2 font-mono text-xs font-semibold text-inst-900">{{ $item->clave_origen }}</td>
                            <td class="px-3 py-2 text-xs font-medium text-slate-900">{{ $item->agrupamiento }}</td>
                            <td class="px-3 py-2 text-xs text-slate-700">{{ $item->municipio }}</td>
                            <td class="px-3 py-2 text-xs text-tx-soft">{{ $item->region }}</td>
                            <td class="px-3 py-2 text-xs text-tx-soft">{{ $item->subdireccion }}</td>
                            <td class="px-3 py-2 text-xs text-tx-soft">{{ $item->coordinacion }}</td>
                            <td class="px-3 py-2 text-xs text-tx-soft">{{ $item->direccion_general }}</td>
                            <td class="px-3 py-2 text-xs text-tx-soft">{{ $item->zona_militar }}</td>
                            <td class="px-3 py-2 text-xs text-tx-soft">{{ $item->batallon ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-sm text-tx-soft">
                                No hay registros que coincidan o aún no se ha publicado una versión del catálogo.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $records->links() }}
        </div>
    </x-card>
</div>
@endsection

