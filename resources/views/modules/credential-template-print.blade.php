@extends('layouts.app')

@php
    $esReal = (bool) ($isRealElement ?? false);
    $credencialReal = ($elementoReal ?? null)?->credencialEmitida;
    $yaEmitida = (bool) $credencialReal?->estaEmitida();
    // Fase 1B.2: una credencial real solo se renderiza desde el snapshot de una preparación.
    $prep = $preparacion ?? null;
    $esReimpresion = $prep?->tipo_operacion === \App\Models\CredencialPreparacion::TIPO_REIMPRESION_HISTORICA;
    $vistaVigente = (bool) $prep?->vistaVigente();
    $confirmacion = $prep?->impresion;
    $estadosPrep = ['preparada' => 'vigente', 'confirmada' => 'confirmada', 'cancelada' => 'cancelada', 'expirada' => 'expirada'];
@endphp

@section('title', $esReal ? 'Impresión de credencial' : 'Vista de impresión de demostración')
@section('heading', $esReal ? 'Impresión de credencial' : 'Vista de impresión · demostración')
@section('subheading')
    @if ($esReal)
        Versión aprobada v{{ $templateVersion }} · formato 54 × 86 mm · folio {{ $credencialReal?->folio }}
    @else
        Versión aprobada v{{ $templateVersion }} · formato 54 × 86 mm · solo demostración
    @endif
@endsection

@section('content')
    @if ($esReal && $prep === null)
        {{-- Lanzador: abrir o recargar esta página no crea preparaciones ni muestra datos vigentes. --}}
        <div class="print-toolbar mb-5 rounded-lg bg-white p-4 shadow-card ring-1 ring-slate-900/5" data-print-launcher>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-inst-900">Folio {{ $credencialReal?->folio }} · CUIP {{ $elementoReal->cuip }}</p>
                    <p class="mt-1 text-xs text-tx-soft">La credencial se imprime desde una preparación: los cuatro biométricos, la plantilla y los datos utilizados se conservan como evidencia.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('impresion') }}" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-tx shadow-sm hover:bg-slate-50">Volver a la cola</a>
                    @if ($yaEmitida)
                        <x-status-badge label="Credencial emitida" tone="success"/>
                    @endif
                    @if ($preparacionActiva ?? null)
                        <a href="{{ route('impresion.preparacion', $preparacionActiva) }}" data-continuar-preparacion class="rounded-md border border-inst-200 bg-inst-50 px-3 py-2 text-sm font-medium text-inst-800 hover:bg-inst-100">Continuar preparación vigente</a>
                    @endif
                    @if ($puedeRegistrarImpresion ?? false)
                        <form method="POST" action="{{ route('impresion.elemento.preparar', $elementoReal) }}" data-preparar-form>
                            @csrf
                            <input type="hidden" name="tipo" value="{{ $yaEmitida ? 'reimpresion' : 'emision' }}">
                            <input type="hidden" name="template_version" value="{{ $selectedTemplateVersion ?? 1 }}">
                            <button type="submit" class="rounded-md bg-inst-700 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-inst-900">{{ $yaEmitida ? 'Preparar reimpresión histórica' : 'Preparar impresión' }}</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    @elseif ($esReal)
        <div class="print-toolbar mb-5 rounded-lg bg-white p-4 shadow-card ring-1 ring-slate-900/5"
             data-print-toolbar
             data-preparacion="{{ $prep->uuid }}"
             data-reimpresion="{{ $esReimpresion ? '1' : '0' }}"
             data-confirmar-url="{{ route($esReimpresion ? 'impresion.elemento.reimpresion' : 'impresion.elemento.emision', $elementoReal) }}"
             data-cancelar-url="{{ route('impresion.preparacion.cancelar', $prep) }}"
             data-trabajo-url="{{ route('impresion.preparacion.trabajo', $prep) }}"
             data-trabajo-estado-url="{{ route('impresion.preparacion.trabajo.estado', $prep) }}"
             data-agente-url="{{ $agenteEstacionUrl ?? '' }}"
             data-cola="{{ $colaImpresora ?? '' }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-inst-900">Folio {{ $credencialReal?->folio }} · CUIP {{ $elementoReal->cuip }}</p>
                    <p class="mt-1 text-xs text-tx-soft">
                        {{ $esReimpresion ? 'Reimpresión histórica' : 'Emisión' }} · preparación {{ $estadosPrep[$prep->estado] ?? $prep->estado }}
                        @if ($vistaVigente) · vista disponible hasta {{ $prep->vista_expira_en->format('H:i') }} @endif
                    </p>
                    @if ($vistaVigente)
                        <p class="mt-1 text-xs text-tx-soft" data-print-status>Cargando imágenes biométricas…</p>
                        {{-- Estado de la cola de Windows vía agente de estación: informativo, nunca confirma la tarjeta física. --}}
                        <p class="mt-1 text-xs text-tx-soft" data-cola-estado aria-live="polite"></p>
                    @elseif ($prep->estaPreparada())
                        <p class="mt-1 text-xs font-medium text-amber-800" data-print-status>La vista de esta preparación expiró: no se sirven imágenes. Prepare nuevamente para imprimir.</p>
                    @elseif ($confirmacion)
                        <p class="mt-1 text-xs font-medium text-tx" data-print-status>Resultado registrado: {{ $confirmacion->resultado }} (intento {{ $confirmacion->numero_intento }}{{ $confirmacion->modalidad_confirmacion === 'extemporanea' ? ', extemporáneo' : '' }}).</p>
                    @else
                        <p class="mt-1 text-xs font-medium text-amber-800" data-print-status>Preparación {{ $estadosPrep[$prep->estado] ?? $prep->estado }}{{ $prep->motivo_cancelacion ? ' ('.str_replace('_', ' ', $prep->motivo_cancelacion).')' : '' }}: no se sirven imágenes.</p>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('impresion') }}" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-tx shadow-sm hover:bg-slate-50">Volver a la cola</a>
                    @if ($vistaVigente)
                        <button type="button" data-print-button disabled class="rounded-md bg-inst-700 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-inst-900 disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-500">{{ $esReimpresion ? 'Imprimir reimpresión' : 'Imprimir credencial' }}</button>
                        <button type="button" data-cancelar class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-tx shadow-sm hover:bg-slate-50">Cancelar preparación</button>
                    @elseif ($prep->estaPreparada() && ($puedeRegistrarImpresion ?? false))
                        <form method="POST" action="{{ route('impresion.elemento.preparar', $elementoReal) }}">
                            @csrf
                            <input type="hidden" name="tipo" value="{{ $esReimpresion ? 'reimpresion' : 'emision' }}">
                            <input type="hidden" name="template_version" value="{{ $selectedTemplateVersion ?? 1 }}">
                            <button type="submit" class="rounded-md bg-inst-700 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-inst-900">Preparar nuevamente</button>
                        </form>
                    @endif
                </div>
            </div>

            @if (($puedeRegistrarImpresion ?? false) && $prep->estaPreparada() && $confirmacion === null)
                <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
                    <span class="text-xs text-tx-soft">Después de imprimir, confirme el resultado:</span>
                    {{-- Deshabilitado hasta que las cuatro evidencias carguen; el servidor lo valida por su cuenta. --}}
                    <button type="button" data-registrar="impresa" disabled class="rounded-md bg-estado-exito px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-emerald-800 disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-500">{{ $esReimpresion ? 'Reimpresión correcta' : 'Impresión correcta' }}</button>
                    <button type="button" data-registrar="fallida" class="rounded-md border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-800 hover:bg-red-100">Reportar falla de impresión</button>
                </div>
            @endif

            @if (($puedeRegistrarImpresion ?? false) && ($extemporaneaPermitida ?? false))
                {{-- Confirmación extemporánea: declaración auditada de una impresión física ocurrida después de cancelar o expirar. --}}
                <div class="mt-3 space-y-2 border-t border-slate-100 pt-3" data-extemporanea-panel>
                    <p class="text-xs text-tx">Si la tarjeta llegó a imprimirse desde esta preparación, documente el resultado. La preparación conserva su estado y su evidencia.</p>
                    <label for="justificacion-extemporanea" class="block text-xs font-medium text-tx">Justificación (mínimo 10 caracteres)</label>
                    <textarea id="justificacion-extemporanea" data-justificacion rows="2" required minlength="10" maxlength="1000"
                              class="block w-full rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inst-700"></textarea>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" data-extemporanea="impresa" class="rounded-md bg-estado-exito px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-emerald-800">Registrar impresión ocurrida (extemporánea)</button>
                        <button type="button" data-extemporanea="fallida" class="rounded-md border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-800 hover:bg-red-100">Registrar falla ocurrida (extemporánea)</button>
                    </div>
                </div>
            @endif
            <p class="mt-2 text-xs" data-registro-mensaje role="status"></p>

            @if ($esReimpresion)
                <dialog data-reimpresion-dialog class="w-full max-w-md rounded-lg p-0 shadow-xl backdrop:bg-slate-900/40">
                    <form method="dialog" data-reimpresion-form class="p-5">
                        <h2 class="text-sm font-semibold text-inst-900">Registrar reimpresión</h2>
                        <p class="mt-1 text-xs text-tx-soft">Folio {{ $credencialReal?->folio }}. El motivo queda en la bitácora y se conserva el folio original.</p>
                        <label for="motivo-reimpresion" class="mt-4 block text-xs font-medium text-tx">Motivo (mínimo 10 caracteres)</label>
                        <textarea id="motivo-reimpresion" name="motivo" rows="3" required minlength="10" maxlength="500"
                                  class="mt-1 block w-full rounded-md border-0 px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inst-700"></textarea>
                        <div class="mt-4 flex justify-end gap-2">
                            <button type="button" data-reimpresion-cancelar class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-tx shadow-sm hover:bg-slate-50">Cancelar</button>
                            <button type="submit" class="rounded-md bg-inst-700 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-inst-900">Registrar</button>
                        </div>
                    </form>
                </dialog>
            @endif
        </div>
    @else
        <div class="print-toolbar mb-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4">
            <p class="text-sm text-amber-950">Prueba de salida con marcadores de posición. No es una credencial emitida ni válida.</p>
            <div class="flex gap-2">
                <a href="{{ route('impresion.plantilla', ['template_version' => $selectedTemplateVersion ?? 1]) }}" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium">Volver al editor</a>
                <button type="button" onclick="window.print()" class="rounded-md bg-inst-700 px-3 py-2 text-sm font-semibold text-white">Imprimir demostración</button>
            </div>
        </div>

        <div class="print-demo-disclaimer mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-900">
            SOLO DEMOSTRACIÓN — no contiene ni genera datos reales de personal o credenciales.
        </div>
    @endif

    @if (! $esReal || $prep !== null)
    <div class="credential-print-pages">
        @foreach (['front' => 'Anverso', 'rear' => 'Reverso'] as $face => $label)
            <section class="credential-print-page" aria-label="{{ $label }}">
                <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500 print-face-label">{{ $label }} · v{{ $templateVersion }}</div>
                <div class="credential-print-card" style="width: {{ $templateConfig['width_mm'] }}mm; height: {{ $templateConfig['height_mm'] }}mm;">
                    <img class="credential-background" src="{{ asset('credentials/'.$face.'.png') }}" alt="{{ $label }}">
                    @foreach ($templateConfig['faces'][$face]['elements'] as $element)
                        @if ($element['visible'])
                            @php
                                $kind = $element['kind'] ?? ($element['id'] === 'leyenda_acredita' ? 'static' : ($element['field'] === 'codigo_barras' ? 'barcode' : 'dynamic'));
                                $displayValue = $kind === 'static'
                                    ? ($element['content'] ?? ($element['id'] === 'leyenda_acredita' ? 'Se acredita a:' : ''))
                                    : ($kind === 'barcode'
                                        ? ($demoData[$element['field']] ?? ($element['content'] ?? 'CÓDIGO DE BARRAS'))
                                        : (($element['label'] ?? null)
                                            ? $element['label']."\n".($demoData[$element['field']] ?? 'DATO PENDIENTE')
                                            : ($demoData[$element['field']] ?? 'DATO DE DEMOSTRACIÓN')));
                                $biometricUrl = $biometricUrls[$element['field']] ?? null;

                                $rotation = (int) ($element['rotation'] ?? 0);
                                $isRotated = $rotation !== 0;
                                $width = (float) $element['width'];
                                $height = (float) $element['height'];
                                // Proporción física de la tarjeta (86/54): un texto rotado recorre el alto real de su caja.
                                $aspect = (float) $templateConfig['height_mm'] / (float) $templateConfig['width_mm'];
                                $spanWidth = $isRotated && $width > 0 ? number_format(($height / $width) * $aspect * 100, 2, '.', '').'%' : '100%';
                                $spanHeight = $isRotated && $height > 0 ? number_format(($width / $height) / $aspect * 100, 2, '.', '').'%' : '100%';
                                $whiteSpace = in_array($element['field'] ?? null, ['folio', 'cuip'], true) ? 'nowrap' : 'pre-line';
                                $spanTransform = 'translate(-50%, -50%)' . ($isRotated ? " rotate({$rotation}deg)" : '');
                            @endphp
                            <div class="credential-print-element {{ $kind === 'image' && empty($biometricUrl) && empty($element['asset_path']) ? 'credential-demo-image' : '' }}"
                                 data-font-size="{{ $element['font_size'] }}"
                                 data-rotation="{{ $element['rotation'] }}"
                                 data-width="{{ $element['width'] }}"
                                 data-height="{{ $element['height'] }}"
                                 style="left: {{ $element['x'] }}%; top: {{ $element['y'] }}%; width: {{ $element['width'] }}%; height: {{ $element['height'] }}%; font-weight: {{ $element['weight'] }}; color: {{ $element['color'] }}; text-align: {{ $element['align'] }}; line-height: {{ $element['line_height'] ?? 1 }}; {{ ! empty($element['border']) ? 'border: '.$element['border'].';' : '' }}">
                                
                                @if ($kind === 'image')
                                    @if (! empty($biometricUrl))
                                        @php $categoriaEvidencia = \App\Services\CredencialPreparacionService::CAMPOS_BIOMETRICOS[$element['field']] ?? null; @endphp
                                        <img class="credential-element-resource"
                                             style="width: 100%; height: 100%; object-fit: {{ $element['field'] === 'fotografia' ? 'cover' : 'contain' }}; {{ ! empty($element['border']) ? 'border: '.$element['border'].';' : '' }}"
                                             src="{{ $biometricUrl }}"
                                             @if ($categoriaEvidencia) data-evidencia="{{ $categoriaEvidencia->value }}" data-evidencia-etiqueta="{{ $categoriaEvidencia->label() }}" @endif
                                             alt="{{ $element['content'] ?? '' }}">
                                    @elseif (! empty($element['asset_path']))
                                        <img class="credential-element-resource"
                                             style="width: 100%; height: 100%; object-fit: contain;"
                                             src="{{ route('impresion.plantilla.recurso', ['filename' => $element['asset_path']]) }}"
                                             alt="{{ $element['content'] ?? '' }}">
                                        <span hidden>{{ $displayValue }}</span>
                                    @elseif ($element['field'] === 'fotografia')
                                        {{-- Fallback pericial institucional para fotografía facial --}}
                                        <div class="w-full h-full flex flex-col items-center justify-center bg-slate-100/80 rounded-xs border border-slate-300 text-slate-400 select-none">
                                            <svg class="w-6 h-6 text-slate-300" viewBox="0 0 24 24" fill="currentColor">
                                                <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                                            </svg>
                                            <span class="text-[5pt] font-bold text-slate-500 uppercase tracking-tight mt-0.5">Sin foto</span>
                                        </div>
                                    @elseif (in_array($element['field'], ['firma_titular', 'firma_persona'], true))
                                        {{-- Fallback pericial institucional para firma del elemento --}}
                                        <div class="w-full h-full flex items-end justify-center border-b border-dashed border-slate-400 pb-0.5 select-none">
                                            <span class="text-[4.5pt] italic text-slate-400">Firma pendiente</span>
                                        </div>
                                    @elseif (in_array($element['field'], ['huella_izquierda', 'huella_derecha'], true))
                                        {{-- Fallback pericial institucional para huellas dactilares --}}
                                        <div class="w-full h-full flex flex-col items-center justify-center bg-slate-50/60 rounded-xs border border-dashed border-slate-300 text-slate-400 p-0.5 select-none">
                                            <svg class="w-5 h-5 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 004.07 9m5.93 11a21.84 21.84 0 004.5-2.5" />
                                            </svg>
                                            <span class="text-[4pt] font-semibold text-slate-500">Pendiente</span>
                                        </div>
                                    @else
                                        <span style="font-family: {{ $element['font_family'] ?? 'Inter' }}; white-space: pre-line;">{{ $displayValue }}</span>
                                    @endif
                                @elseif ($kind === 'barcode')
                                    {{-- Renderizado vectorial nítido para código de barras --}}
                                    <div class="w-full h-full flex flex-col items-center justify-center bg-white/90 p-0.5 rounded-xs border border-slate-300 select-none">
                                        @php
                                            // Code 128 real del valor del campo (CUIP); si no es codificable se muestra solo el texto.
                                            $barcodeSvg = \App\Support\Code128::svg((string) $displayValue, 'w-full h-3 text-slate-900');
                                        @endphp
                                        @if ($barcodeSvg)
                                            {!! $barcodeSvg !!}
                                        @else
                                            <div class="h-3 w-full rounded-xs border border-dashed border-slate-400" aria-hidden="true"></div>
                                        @endif

                                        <span class="font-mono text-[5pt] font-bold text-slate-900 tracking-wider">{{ $displayValue }}</span>
                                    </div>
                                @else
                                    <span style="position: absolute; top: 50%; left: 50%; width: {{ $spanWidth }}; height: {{ $spanHeight }}; transform: {{ $spanTransform }}; font-family: {{ $element['font_family'] ?? ($element['field'] === 'folio' || $element['field'] === 'cuip' ? 'monospace' : 'Inter, sans-serif') }}; font-size: {{ $element['font_size'] }}pt; display: flex; align-items: center; justify-content: {{ $element['align'] === 'left' ? 'flex-start' : ($element['align'] === 'right' ? 'flex-end' : 'center') }}; white-space: {{ $whiteSpace }};">{{ $displayValue }}</span>
                                @endif
                            </div>
                        @endif
                    @endforeach
                    @if (! ($isRealElement ?? false))
                        <span class="credential-demo-watermark">DEMOSTRACIÓN</span>
                    @endif
                </div>
            </section>
        @endforeach
    </div>
    @endif
@endsection

@push('scripts')
    <script>
        (() => {
            const toolbar = document.querySelector('[data-print-toolbar]');
            if (!toolbar) return;

            const boton = toolbar.querySelector('[data-print-button]');
            const estado = toolbar.querySelector('[data-print-status]');
            const mensaje = toolbar.querySelector('[data-registro-mensaje]');
            const imagenes = [...document.querySelectorAll('.credential-print-pages img')];
            const esReimpresion = toolbar.dataset.reimpresion === '1';
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            // Imprimir solo cuando todas las imágenes del snapshot estén decodificadas.
            // Imprimir y confirmar "correcta" solo cuando las cuatro evidencias del snapshot estén decodificadas.
            // Es una ayuda de interfaz: el servidor exige por su cuenta las cuatro entregas registradas.
            const CATEGORIAS = ['foto', 'firma', 'pulgar_derecho', 'pulgar_izquierdo'];
            let evidenciasListas = false;
            const habilitarCorrecta = () => toolbar.querySelectorAll('[data-registrar="impresa"]').forEach((b) => (b.disabled = !evidenciasListas));
            if (boton) {
                Promise.allSettled(imagenes.map((img) => img.decode().then(() => img))).then((resultados) => {
                    const fallidas = resultados.filter((r) => r.status === 'rejected').length;
                    const cargadas = new Set(resultados.filter((r) => r.status === 'fulfilled').map((r) => r.value.dataset.evidencia).filter(Boolean));
                    const etiquetas = Object.fromEntries(imagenes.filter((i) => i.dataset.evidencia).map((i) => [i.dataset.evidencia, i.dataset.evidenciaEtiqueta]));
                    const faltantes = CATEGORIAS.filter((c) => !cargadas.has(c)).map((c) => etiquetas[c] || c);
                    if (fallidas > 0 || faltantes.length > 0) {
                        estado.textContent = faltantes.length > 0
                            ? `No se cargaron estas evidencias: ${faltantes.join(', ')}. Recargue la página antes de imprimir.`
                            : `No se pudieron cargar ${fallidas} imagen(es). Recargue la página antes de imprimir.`;
                        estado.className = 'mt-1 text-xs font-medium text-estado-error';
                        return;
                    }
                    evidenciasListas = true;
                    estado.textContent = 'Las cuatro evidencias biométricas se cargaron. Lista para imprimir.';
                    boton.disabled = false;
                    habilitarCorrecta();
                });
                boton.addEventListener('click', () => imprimir());
            }

            // ---- Envío a la impresora y seguimiento de la cola de Windows (agente de estación, solo lectura) ----
            const lineaCola = toolbar.querySelector('[data-cola-estado]');
            const seguimiento = toolbar.dataset.agenteUrl && typeof window.crearSeguimientoImpresion === 'function'
                ? window.crearSeguimientoImpresion({ agenteUrl: toolbar.dataset.agenteUrl })
                : null;
            let colaObservada = null; // Solo se envía en la confirmación si el trabajo se vio en la cola Zebra.
            const mostrarCola = (texto, tono = 'text-tx-soft') => {
                if (!lineaCola) return;
                lineaCola.textContent = texto;
                lineaCola.className = `mt-1 text-xs ${tono}`;
            };
            const verificarCola = async () => {
                if (!seguimiento) return mostrarCola('Sin seguimiento de cola: elija la impresora Zebra en el diálogo de impresión.');
                const r = await seguimiento.verificar();
                const ok = r.estado === 'lista';
                mostrarCola(`${r.simulacion ? 'SIMULACIÓN · ' : ''}${r.mensaje}${r.cola ? ` (${r.cola})` : ''}${r.alertas.length ? ` · ${r.alertas.join(', ')}` : ''}`, ok ? 'text-estado-exito' : 'font-medium text-amber-800');
            };
            if (boton) verificarCola();

            const autorizarEnvio = async () => {
                try {
                    return await postJson(toolbar.dataset.trabajoUrl, {});
                } catch (err) {
                    if (err.codigo !== 'trabajo_previo') throw err;
                    // Reenviar la misma preparación imprimiría otra tarjeta: exige revisar y justificar.
                    const motivo = prompt(`${err.message}\n\nMotivo del reenvío (mínimo 10 caracteres):`);
                    if (!motivo || motivo.trim().length < 10) throw new Error('Reenvío cancelado: no se envió ningún trabajo.');
                    return postJson(toolbar.dataset.trabajoUrl, { motivo_reenvio: motivo.trim() });
                }
            };
            const informarEstado = (trabajo, info) => postJson(toolbar.dataset.trabajoEstadoUrl, {
                trabajo, estado: info.estado, job_id: info.job_id, cola: info.cola, alertas: info.alertas, simulacion: info.simulacion,
            }).catch(() => {}); // El registro es auditoría; un fallo de red no debe ocultar el estado al operador.

            const imprimir = async () => {
                boton.disabled = true;
                try {
                    const envio = await autorizarEnvio();
                    let documento = envio.documento;
                    let conSeguimiento = false;
                    if (seguimiento && envio.ticket) {
                        try {
                            documento = await seguimiento.abrir(envio.ticket, envio.trabajo);
                            conSeguimiento = true;
                        } catch (err) {
                            mostrarCola(`Sin seguimiento de cola: ${err.message}`, 'font-medium text-amber-800');
                        }
                    }
                    // El título de la página es el nombre del documento en la cola: lleva solo el identificador, sin datos personales.
                    const titulo = document.title;
                    document.title = documento;
                    window.print();
                    document.title = titulo;

                    if (!conSeguimiento) return;
                    mostrarCola(`Buscando el trabajo ${documento} en la cola…`);
                    seguimiento.seguir((info) => {
                        if (info.estado === 'sin_seguimiento') return mostrarCola(`Sin seguimiento de cola: ${info.mensaje}`, 'font-medium text-amber-800');
                        const enZebra = info.estado !== 'en_otra_cola' && info.estado !== 'no_encontrado' && info.cola === toolbar.dataset.cola;
                        if (enZebra) colaObservada = info.cola;
                        informarEstado(envio.trabajo, info);
                        const tono = ['error', 'en_otra_cola', 'eliminado', 'no_encontrado'].includes(info.estado) ? 'font-medium text-amber-800' : 'text-tx-soft';
                        const job = info.job_id !== null && info.job_id !== undefined ? ` (trabajo #${info.job_id})` : '';
                        const otra = info.estado === 'en_otra_cola' && info.cola ? `: ${info.cola}` : '';
                        const alertas = info.alertas?.length ? ` · ${info.alertas.join(', ')}` : '';
                        mostrarCola(`${info.simulacion ? 'SIMULACIÓN · ' : ''}${info.mensaje}${otra}${job}${alertas}. Esto no confirma la tarjeta física: revísela y registre el resultado.`, tono);
                        if (info.final) seguimiento.cerrar();
                    });
                } catch (err) {
                    mostrarCola(err.message, 'font-medium text-estado-error');
                } finally {
                    boton.disabled = !evidenciasListas;
                }
            };

            const botonesAccion = () => toolbar.querySelectorAll('[data-registrar], [data-extemporanea], [data-cancelar]');
            const postJson = async (url, body) => {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(body),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    const error = new Error(data.errors ? Object.values(data.errors)[0][0] : (data.message || 'No se pudo completar la operación.'));
                    error.codigo = data.codigo || null;
                    throw error;
                }
                return data;
            };
            const confirmar = async (body) => {
                botonesAccion().forEach((b) => (b.disabled = true));
                try {
                    // La cola observada solo documenta por dónde salió el trabajo; el resultado lo declara el operador.
                    const impresora = colaObservada ? { impresora: colaObservada } : {};
                    const data = await postJson(toolbar.dataset.confirmarUrl, { preparacion_uuid: toolbar.dataset.preparacion, ...impresora, ...body });
                    mensaje.textContent = `${data.message} (intento ${data.numero_intento})`;
                    mensaje.className = 'mt-2 text-xs font-medium text-estado-exito';
                    setTimeout(() => window.location.reload(), 1200);
                } catch (err) {
                    mensaje.textContent = err.message;
                    mensaje.className = 'mt-2 text-xs font-medium text-estado-error';
                    botonesAccion().forEach((b) => (b.disabled = false));
                    habilitarCorrecta(); // tras un error, "correcta" sigue condicionada a las cuatro evidencias
                }
            };

            // Reimpresión: el motivo se pide al confirmar el resultado físico.
            const dialogo = toolbar.querySelector('[data-reimpresion-dialog]');
            const pedirMotivo = (alEnviar) => {
                const form = dialogo.querySelector('[data-reimpresion-form]');
                form.onsubmit = (evento) => {
                    evento.preventDefault();
                    const motivo = form.motivo.value.trim();
                    dialogo.close();
                    alEnviar(motivo);
                };
                dialogo.showModal();
            };
            dialogo?.querySelector('[data-reimpresion-cancelar]').addEventListener('click', () => dialogo.close());

            toolbar.querySelectorAll('[data-registrar]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const resultado = btn.dataset.registrar === 'fallida' ? 'fallida' : 'impresa';
                    if (esReimpresion) {
                        pedirMotivo((motivo) => confirmar({ resultado, motivo }));
                        return;
                    }
                    let motivo = null;
                    if (resultado === 'fallida') {
                        motivo = prompt('Describa la falla de impresión (opcional):') ?? null;
                    } else if (!confirm('¿Confirma que la credencial se imprimió correctamente? Después la biometría quedará bloqueada.')) {
                        return;
                    }
                    confirmar({ resultado, motivo });
                });
            });

            toolbar.querySelectorAll('[data-extemporanea]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const justificacion = toolbar.querySelector('[data-justificacion]').value.trim();
                    const body = { resultado: btn.dataset.extemporanea, extemporanea: true, justificacion_extemporanea: justificacion };
                    if (esReimpresion) {
                        pedirMotivo((motivo) => confirmar({ ...body, motivo }));
                        return;
                    }
                    confirmar(body);
                });
            });

            toolbar.querySelector('[data-cancelar]')?.addEventListener('click', async () => {
                const motivo = prompt('Motivo de la cancelación (mínimo 5 caracteres):');
                if (!motivo) return;
                try {
                    await postJson(toolbar.dataset.cancelarUrl, { motivo: motivo.trim() });
                    window.location.reload();
                } catch (err) {
                    mensaje.textContent = err.message;
                    mensaje.className = 'mt-2 text-xs font-medium text-estado-error';
                }
            });
        })();
    </script>
@endpush

@push('styles')
    <style>
        @page {
            size: 54mm 86mm;
            margin: 0;
        }
    </style>
@endpush
