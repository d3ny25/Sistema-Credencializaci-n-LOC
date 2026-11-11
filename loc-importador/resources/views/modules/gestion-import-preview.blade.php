@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-sm font-medium text-slate-500">Gestión de información / Personal autorizado LOC</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">Validación previa de importación</h1>
            <p class="mt-2 text-sm text-slate-600">{{ $batch->nombre_archivo }} · Lote #{{ $batch->id }}</p>
        </div>
        <a href="{{ route('gestion') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Volver a fuentes</a>
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
        @foreach ([
            'nuevo' => ['Nuevos', 'text-emerald-700'],
            'actualizar' => ['Por actualizar', 'text-blue-700'],
            'sin_cambios' => ['Sin cambios', 'text-slate-700'],
            'rechazado' => ['Rechazados', 'text-red-700'],
            'pendiente' => ['Pendientes', 'text-amber-700'],
        ] as $key => $meta)
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-sm text-slate-500">{{ $meta[0] }}</div>
                <div class="mt-2 text-2xl font-semibold {{ $meta[1] }}">{{ number_format((int) ($counts[$key] ?? 0)) }}</div>
            </div>
        @endforeach
    </div>

    <div class="mt-6 rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="font-semibold text-slate-900">Detalle por fila</h2>
            <p class="mt-1 text-sm text-slate-500">Las filas rechazadas no se aplicarán. Confirma sólo después de revisar el resumen.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr><th class="px-4 py-3">Fila</th><th class="px-4 py-3">CUIP</th><th class="px-4 py-3">Nombre</th><th class="px-4 py-3">Acción</th><th class="px-4 py-3">Observaciones</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        @php($data = $row->datos['campos'] ?? [])
                        @php($errorsForRow = $row->errores ?? [])
                        <tr class="align-top">
                            <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ $row->numero_fila }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-slate-700">{{ $row->cuip ?: '—' }}</td>
                            <td class="min-w-56 px-4 py-3 text-slate-800">{{ $data['nombre'] ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">{{ str_replace('_', ' ', $row->accion) }}</span>
                            </td>
                            <td class="min-w-64 px-4 py-3 text-slate-600">
                                @if (count($errorsForRow))
                                    <ul class="list-inside list-disc text-red-700">@foreach ($errorsForRow as $error)<li>{{ $error }}</li>@endforeach</ul>
                                @elseif ($row->accion === 'nuevo')
                                    Registro nuevo.
                                @elseif ($row->accion === 'actualizar')
                                    Se actualizarán los campos reconocidos.
                                @elseif ($row->accion === 'sin_cambios')
                                    Los campos reconocidos coinciden con el padrón.
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">No hay filas para mostrar.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-200 px-4 py-3">{{ $rows->links() }}</div>
    </div>

    <div class="mt-6 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
        <p class="max-w-3xl text-sm text-amber-900">La confirmación escribe los registros nuevos y actualiza coincidencias por CUIP. Los registros con errores se conservarán en el historial y no se importarán.</p>
        <form method="POST" action="{{ route('gestion.importaciones.confirmar', $batch) }}" onsubmit="return confirm('¿Confirmas aplicar los registros válidos de esta importación?');">
            @csrf
            <button type="submit" @disabled($batch->estado !== 'en_revision' || (($counts['nuevo'] ?? 0) + ($counts['actualizar'] ?? 0) + ($counts['sin_cambios'] ?? 0) === 0)) class="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-40">
                Confirmar importación
            </button>
        </form>
    </div>
</div>
@endsection
