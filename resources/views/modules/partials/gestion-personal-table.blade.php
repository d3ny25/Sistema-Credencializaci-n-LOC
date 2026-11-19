<div class="overflow-x-auto rounded-lg ring-1 ring-slate-900/5">
    <table class="min-w-full divide-y divide-slate-100 text-left text-sm">
        <thead class="bg-slate-50/80 text-left text-xs font-semibold uppercase tracking-wide text-tx-soft">
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
                <tr class="align-top hover:bg-slate-50/60">
                    <td class="whitespace-nowrap px-4 py-3 text-tx-soft">{{ $row->numero_fila }}</td>
                    <td class="whitespace-nowrap px-4 py-3 font-mono text-tx">{{ $row->cuip ?: '—' }}</td>
                    <td class="min-w-56 px-4 py-3 text-tx">{{ $data['nombre'] ?? '—' }}</td>
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
                            <span class="mt-1 block text-xs text-tx-soft">Aplicación: {{ str_replace('_', ' ', $row->estado_aplicacion) }}</span>
                        @endif
                    </td>
                    <td class="min-w-64 px-4 py-3 text-tx-soft">
                        @if (!empty($row->datos['exclusion']))
                            <div class="mb-1 rounded border border-purple-200 bg-purple-50 p-2 text-xs text-purple-900">
                                <p class="font-semibold">Fila omitida formalmente:</p>
                                <p>{{ $row->datos['exclusion']['motivo'] ?? 'Sin motivo registrado' }}</p>
                                @if (!empty($row->datos['exclusion']['fecha']))
                                    <p class="mt-0.5 text-2xs text-purple-700">Fecha: {{ $row->datos['exclusion']['fecha'] }}</p>
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
                                <summary class="cursor-pointer font-medium text-tx hover:text-tx hover:underline">Omitir formalmente con trazabilidad</summary>
                                <form method="POST" action="{{ route('gestion.importaciones.omitir', [$batch, $row]) }}" class="mt-2 space-y-2 rounded bg-slate-50 p-2">
                                    @csrf
                                    <input type="text" name="motivo" placeholder="Motivo de exclusión..." required minlength="5" class="w-full rounded border border-slate-300 px-2 py-1 text-xs text-tx">
                                    <button type="submit" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-tx shadow-sm hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-inst-700 focus:ring-offset-1">Confirmar exclusión</button>
                                </form>
                            </details>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-10 text-center text-tx-soft">
                        No hay filas para mostrar con los filtros aplicados.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4 border-t border-slate-100 pt-3">
    {{ $rows->links() }}
</div>

