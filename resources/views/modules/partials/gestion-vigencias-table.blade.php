<div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-slate-200 text-left text-xs">
        <thead class="bg-slate-50 uppercase tracking-wide text-tx-soft">
            <tr>
                <th class="px-3 py-2">Fila</th>
                <th class="px-3 py-2">CUIP</th>
                <th class="px-3 py-2">No. Credencial</th>
                <th class="px-3 py-2">Estatus</th>
                <th class="px-3 py-2">Conciliación Personal</th>
                <th class="px-3 py-2">Armas (Corta / Larga)</th>
                <th class="px-3 py-2">Acción BD</th>
                <th class="px-3 py-2">Efecto en Trámite</th>
                <th class="px-3 py-2">Diagnóstico / Incidencias</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($rows as $row)
                @php
                    $data = is_array($row->datos) ? $row->datos : json_decode((string) $row->datos, true);
                    $campos = $data['campos'] ?? [];
                    $conciliacion = $data['conciliacion'] ?? [];
                    $tramiteEfecto = $data['tramite_efecto'] ?? null;
                    $errores = is_array($row->errores) ? $row->errores : (json_decode((string) $row->errores, true) ?: []);
                    $incidencias = $conciliacion['incidencias'] ?? [];
                    $isStructuralError = in_array($row->accion, ['conflicto', 'rechazado']);
                @endphp
                <tr class="align-top hover:bg-slate-50/60">
                    <td class="px-3 py-2 font-mono text-slate-500">{{ $row->numero_fila }}</td>
                    <td class="px-3 py-2 font-mono font-medium text-slate-800">
                        {{ $row->cuip ?? '—' }}
                    </td>
                    <td class="px-3 py-2 font-medium text-slate-800">{{ $campos['numero_credencial'] ?? 'S/N' }}</td>
                    <td class="px-3 py-2">
                        <span @class([
                            'inline-block rounded px-1.5 py-0.5 font-medium',
                            'bg-emerald-100 text-emerald-800' => ($campos['estatus_credencial'] ?? '') === 'ALTA',
                            'bg-red-100 text-red-800' => ($campos['estatus_credencial'] ?? '') === 'BAJA',
                            'bg-amber-100 text-amber-800' => in_array($campos['estatus_credencial'] ?? '', ['SITUACION ESPECIAL', 'CANCELACION']),
                        ])>
                            {{ $campos['estatus_credencial'] ?? '—' }}
                        </span>
                    </td>
                    <td class="px-3 py-2">
                        @if ($conciliacion['persona_resuelta'] ?? false)
                            <span class="inline-flex items-center gap-1 rounded bg-emerald-100 px-1.5 py-0.5 text-[11px] font-medium text-emerald-800">
                                ✓ Conciliada
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 rounded bg-blue-100 px-1.5 py-0.5 text-[11px] font-medium text-blue-800" title="Se conservará con persona_id = NULL">
                                Sin enlace personal
                            </span>
                        @endif
                    </td>
                    <td class="px-3 py-2 font-mono text-[11px]">
                        <div>Corta: {{ $campos['matricula_corta'] ?? '—' }}
                            @if (!empty($campos['matricula_corta']) && !($conciliacion['arma_corta_resuelta'] ?? false))
                                <span class="text-slate-400 text-[10px]">(no en inv.)</span>
                            @endif
                        </div>
                        <div>Larga: {{ $campos['matricula_larga'] ?? '—' }}
                            @if (!empty($campos['matricula_larga']) && !($conciliacion['arma_larga_resuelta'] ?? false))
                                <span class="text-slate-400 text-[10px]">(no en inv.)</span>
                            @endif
                        </div>
                    </td>
                    <td class="px-3 py-2 font-medium">
                        @php
                            $credBadgeTone = match ($row->accion) {
                                'nuevo' => ($conciliacion['persona_resuelta'] ?? false) ? 'success' : 'info',
                                'actualizar' => 'info',
                                'sin_cambios' => 'neutral',
                                'conflicto', 'rechazado' => 'danger',
                                'omitido' => 'purple',
                                default => 'neutral'
                            };
                            $credBadgeLabel = match ($row->accion) {
                                'nuevo' => ($conciliacion['persona_resuelta'] ?? false) ? 'Insertar (Conciliado)' : 'Insertar (Histórico)',
                                'actualizar' => 'Actualizar',
                                'sin_cambios' => 'Sin cambios',
                                'conflicto' => 'Conflicto',
                                'rechazado' => 'Rechazado',
                                'omitido' => 'Omitida (Excluida)',
                                default => str($row->accion)->replace('_', ' ')->title(),
                            };
                        @endphp
                        <x-status-badge :label="$credBadgeLabel" :tone="$credBadgeTone" size="xs"/>
                    </td>
                    <td class="px-3 py-2">
                        @if ($row->accion === 'omitido')
                            <span class="inline-block rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-600">
                                Excluida de base
                            </span>
                        @elseif ($tramiteEfecto)
                            <span @class([
                                'inline-block rounded px-1.5 py-0.5 text-[10px] font-medium',
                                'bg-red-100 text-red-800' => ($tramiteEfecto['tono'] ?? '') === 'danger',
                                'bg-amber-100 text-amber-800' => ($tramiteEfecto['tono'] ?? '') === 'warning',
                                'bg-emerald-100 text-emerald-800' => ($tramiteEfecto['tono'] ?? '') === 'success',
                                'bg-slate-100 text-slate-700' => ($tramiteEfecto['tono'] ?? '') === 'neutral',
                            ])>
                                {{ $tramiteEfecto['etiqueta'] ?? 'Por evaluar' }}
                            </span>
                        @else
                            <span class="text-slate-400">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-tx-soft">
                        @if (!empty($data['exclusion']))
                            <div class="mb-1 rounded border border-purple-200 bg-purple-50/80 p-1.5 text-purple-950">
                                <p class="font-medium text-[11px]">Excluida formalmente:</p>
                                <p class="text-[10px]">{{ $data['exclusion']['motivo'] ?? 'Sin motivo registrado' }}</p>
                                @if (!empty($data['exclusion']['fecha']))
                                    <p class="mt-0.5 text-[9px] text-purple-700">Fecha: {{ $data['exclusion']['fecha'] }}</p>
                                @endif
                            </div>
                        @endif
                        @if (!empty($errores))
                            <ul class="list-inside list-disc text-red-700">
                                @foreach ($errores as $err)
                                    <li>{{ $err }}</li>
                                @endforeach
                            </ul>
                        @elseif (!empty($incidencias))
                            <ul class="list-inside list-disc text-slate-600">
                                @foreach ($incidencias as $inc)
                                    <li>{{ $inc }}</li>
                                @endforeach
                            </ul>
                        @elseif ($row->accion !== 'omitido')
                            <span class="text-slate-400">Sin incidencias</span>
                        @endif

                        @if ($batch->estado === 'en_revision' && in_array($row->accion, ['conflicto', 'rechazado'], true))
                            <details class="mt-2 text-xs">
                                <summary class="cursor-pointer font-medium text-slate-700 hover:text-slate-900 hover:underline">Omitir formalmente con trazabilidad</summary>
                                <form method="POST" action="{{ route('gestion.vigencias.importaciones.omitir', [$batch, $row]) }}" class="mt-2 space-y-2 rounded bg-slate-50 p-2">
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
                    <td colspan="9" class="px-3 py-8 text-center text-slate-400">
                        No se encontraron filas que coincidan con los filtros aplicados.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4 border-t border-slate-200 pt-3">
    {{ $rows->links() }}
</div>

