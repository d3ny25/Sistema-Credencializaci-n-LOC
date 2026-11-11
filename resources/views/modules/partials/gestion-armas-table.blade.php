@if ($rows->count())
    <div class="overflow-x-auto rounded-lg ring-1 ring-slate-900/5">
        <table class="min-w-full divide-y divide-slate-100 text-left text-sm">
            <thead class="bg-slate-50/80 text-xs uppercase tracking-wide text-tx-soft">
                <tr>
                    <th scope="col" class="whitespace-nowrap px-3 py-3">Fila</th>
                    <th scope="col" class="whitespace-nowrap px-3 py-3">ID externo</th>
                    <th scope="col" class="whitespace-nowrap px-3 py-3">Matrícula</th>
                    <th scope="col" class="whitespace-nowrap px-3 py-3">Marca</th>
                    <th scope="col" class="whitespace-nowrap px-3 py-3">Calibre</th>
                    <th scope="col" class="whitespace-nowrap px-3 py-3">Modelo</th>
                    <th scope="col" class="whitespace-nowrap px-3 py-3">Acción / motivo</th>
                    <th scope="col" class="whitespace-nowrap px-3 py-3">Origen completo</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($rows as $row)
                    @php
                        $fields = is_array($row->datos) ? ($row->datos['campos'] ?? []) : [];
                        $source = is_array($row->datos) ? ($row->datos['origen'] ?? []) : [];
                        $rowErrors = is_array($row->errores) ? $row->errores : [];
                        $diffs = is_array($row->datos['diferencias'] ?? null) ? $row->datos['diferencias'] : [];
                    @endphp
                    <tr class="align-top hover:bg-slate-50/60">
                        <td class="whitespace-nowrap px-3 py-3 text-slate-500">{{ $row->numero_fila ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-3 font-mono text-slate-700">{{ $fields['id_armamento'] ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-3 font-mono font-medium text-slate-900">{{ $fields['matricula'] ?? '—' }}</td>
                        <td class="px-3 py-3 text-slate-700">{{ $fields['marca'] ?? '—' }}</td>
                        <td class="px-3 py-3 text-slate-700">{{ $fields['calibre'] ?? '—' }}</td>
                        <td class="px-3 py-3 text-slate-700">{{ $fields['modelo'] ?? '—' }}</td>
                        <td class="min-w-64 px-3 py-3">
                            @php
                                $armasBadgeTone = match ($row->accion) {
                                    'nuevo' => 'success',
                                    'actualizar' => 'info',
                                    'sin_cambios' => 'neutral',
                                    'conflicto', 'rechazado' => 'danger',
                                    'omitido' => 'purple',
                                    default => 'neutral',
                                };
                                $armasBadgeLabel = match ($row->accion) {
                                    'nuevo' => 'Nueva',
                                    'actualizar' => 'Actualizar',
                                    'sin_cambios' => 'Sin cambios',
                                    'conflicto' => 'Conflicto',
                                    'rechazado' => 'Rechazado',
                                    'omitido' => 'Omitido',
                                    default => str_replace('_', ' ', $row->accion),
                                };
                            @endphp
                            <div class="mb-1">
                                <x-status-badge :label="$armasBadgeLabel" :tone="$armasBadgeTone"/>
                            </div>
                            @if ($row->accion === 'actualizar' && !empty($diffs))
                                <ul class="mt-1 list-inside list-disc text-xs text-tx">
                                    @foreach ($diffs as $field => $d)
                                        <li><span class="font-semibold">{{ $field }}:</span> {{ $d['anterior'] ?: 'vacío' }} &rarr; {{ $d['nuevo'] }}</li>
                                    @endforeach
                                </ul>
                            @elseif ($row->accion === 'omitido')
                                <p class="mt-1 text-xs text-tx-soft">{{ $row->datos['exclusion']['motivo'] ?? 'Excluido formalmente' }}</p>
                            @elseif (in_array($row->accion, ['conflicto', 'rechazado'], true))
                                @if ($rowErrors)
                                    <ul class="mt-1 list-inside list-disc text-estado-error">
                                        @foreach ($rowErrors as $error)
                                            <li>{{ is_scalar($error) ? $error : '—' }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if ($canConfirm && $batch->estado === 'en_revision')
                                    <details class="mt-2 text-xs">
                                        <summary class="cursor-pointer font-medium text-inst-700 hover:underline">Omitir fila formalmente</summary>
                                        <form method="POST" action="{{ route('gestion.armamento.importaciones.omitir', [$batch, $row]) }}" class="mt-2 space-y-2 rounded bg-slate-50 p-2">
                                            @csrf
                                            <input type="text" name="motivo" placeholder="Motivo de exclusión..." required minlength="5" class="w-full rounded border border-slate-300 px-2 py-1 text-xs text-tx">
                                            <button type="submit" class="rounded bg-slate-700 px-2 py-1 text-xs font-medium text-white hover:bg-slate-900">Confirmar exclusión</button>
                                        </form>
                                    </details>
                                @endif
                            @endif
                        </td>
                        <td class="min-w-48 px-3 py-3">
                            <details>
                                <summary class="cursor-pointer text-inst-700 hover:text-inst-900 focus:outline-none focus:ring-2 focus:ring-inst-700">{{ count($source) }} columnas · ver origen completo</summary>
                                <dl class="mt-2 space-y-1 text-xs">
                                    @foreach ($source as $header => $value)
                                        <div><dt class="inline font-semibold">{{ $header }}:</dt> <dd class="inline break-all text-tx-soft">{{ is_scalar($value) ? $value : '—' }}</dd></div>
                                    @endforeach
                                </dl>
                            </details>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4 border-t border-slate-200 pt-3">
        {{ $rows->links() }}
    </div>
@else
    <p class="py-6 text-center text-sm text-tx-soft">
        @if ($onlyErrors)
            No hay excepciones en esta vista. <a href="{{ route('gestion.armamento.importaciones.preview', $batch) }}" class="font-medium text-inst-700 underline">Ver todas las filas</a>
        @else
            No se encontraron filas que coincidan con los filtros aplicados.
        @endif
    </p>
@endif

