<div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3">Fila</th>
                <th class="px-4 py-3">CUIP</th>
                <th class="px-4 py-3">Nombre</th>
                <th class="px-4 py-3">Acción</th>
                <th class="px-4 py-3">Observaciones</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($rows as $row)
                @php
                    $data = $row->datos['campos'] ?? [];
                    $errorsForRow = $row->errores ?? [];
                @endphp
                <tr class="align-top hover:bg-slate-50/70">
                    <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ $row->numero_fila }}</td>
                    <td class="whitespace-nowrap px-4 py-3 font-mono text-slate-700">{{ $row->cuip ?: '—' }}</td>
                    <td class="min-w-56 px-4 py-3 text-slate-800">{{ $data['nombre'] ?? '—' }}</td>
                    <td class="whitespace-nowrap px-4 py-3">
                        <x-status-badge 
                            :label="str_replace('_', ' ', $row->accion)" 
                            :tone="match($row->accion) {
                                'nuevo' => 'success',
                                'actualizar' => 'info',
                                'sin_cambios' => 'neutral',
                                'conflicto', 'rechazado' => 'danger',
                                'omitido' => 'purple',
                                default => 'neutral'
                            }" 
                        />
                        @if ($row->estado_aplicacion !== 'pendiente')
                            <span class="mt-1 block text-xs text-slate-500">Aplicación: {{ str_replace('_', ' ', $row->estado_aplicacion) }}</span>
                        @endif
                    </td>
                    <td class="min-w-64 px-4 py-3 text-slate-600">
                        @if (!empty($row->datos['exclusion']))
                            <div class="mb-1 rounded border border-purple-200 bg-purple-50 p-2 text-xs text-purple-900">
                                <p class="font-semibold">Fila omitida formalmente:</p>
                                <p>{{ $row->datos['exclusion']['motivo'] ?? 'Sin motivo registrado' }}</p>
                                @if (!empty($row->datos['exclusion']['fecha']))
                                    <p class="mt-0.5 text-[10px] text-purple-700">Fecha: {{ $row->datos['exclusion']['fecha'] }}</p>
                                @endif
                            </div>
                        @endif

                        @if (count($errorsForRow))
                            <ul class="list-inside list-disc text-red-700">
                                @foreach ($errorsForRow as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                            @if ($row->accion === 'conflicto')
                                <p class="mt-1 text-orange-800">Todas las filas con este CUIP quedan excluidas de la importación.</p>
                            @endif
                        @elseif ($row->accion === 'conflicto')
                            <span class="text-orange-800">CUIP duplicado en el archivo; todas las filas coincidentes quedan excluidas.</span>
                        @elseif ($row->accion === 'nuevo')
                            Registro nuevo.
                        @elseif ($row->accion === 'actualizar')
                            Se actualizarán los campos reconocidos.
                        @elseif ($row->accion === 'sin_cambios')
                            Los campos reconocidos coinciden con el padrón.
                        @elseif ($row->accion === 'omitido')
                            <span class="text-purple-700">Excluida formalmente de la base de datos oficial.</span>
                        @else
                            —
                        @endif

                        @if (in_array($batch->estado, ['en_revision', 'pendiente'], true) && in_array($row->accion, ['conflicto', 'rechazado'], true))
                            <details class="mt-2 text-xs">
                                <summary class="cursor-pointer font-medium text-slate-700 hover:text-slate-900 hover:underline">Omitir formalmente con trazabilidad</summary>
                                <form method="POST" action="{{ route('gestion.importaciones.omitir', [$batch, $row]) }}" class="mt-2 space-y-2 rounded bg-slate-50 p-2">
                                    @csrf
                                    <input type="text" name="motivo" placeholder="Motivo de exclusión..." required minlength="5" class="w-full rounded border border-slate-300 px-2 py-1 text-xs text-slate-800">
                                    <button type="submit" class="rounded bg-slate-700 px-2 py-1 text-xs font-medium text-white hover:bg-slate-900">Confirmar exclusión</button>
                                </form>
                            </details>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-10 text-center text-slate-500">
                        No hay filas para mostrar con los filtros aplicados.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4 border-t border-slate-200 pt-3">
    {{ $rows->links() }}
</div>

