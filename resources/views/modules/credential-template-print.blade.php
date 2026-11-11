@extends('layouts.app')

@section('title', 'Vista de impresión de demostración')
@section('heading', 'Vista de impresión · demostración')
@section('subheading')
    Versión aprobada v{{ $templateVersion }} · formato 54 × 86 mm · solo demostración
@endsection

@section('content')
    <div class="print-toolbar mb-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4">
        <p class="text-sm text-amber-950">Prueba de salida con marcadores de posición. No es una credencial emitida ni válida.</p>
        <div class="flex gap-2">
            <a href="{{ route('impresion.plantilla', ['template_version' => $selectedTemplateVersion ?? 1]) }}" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium">Volver al editor</a>
            <button type="button" onclick="window.print()" class="rounded-md bg-inst-700 px-3 py-2 text-sm font-semibold text-white">Imprimir demostración</button>
        </div>
    </div>

    @if (! ($isRealElement ?? false))
    <div class="print-demo-disclaimer mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-900">
        SOLO DEMOSTRACIÓN — no contiene ni genera datos reales de personal o credenciales.
    </div>
    @endif

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
                                        ? ($element['content'] ?? ($demoData[$element['field']] ?? 'CÓDIGO DE BARRAS'))
                                        : (($element['label'] ?? null)
                                            ? $element['label']."\n".($demoData[$element['field']] ?? 'DATO PENDIENTE')
                                            : ($demoData[$element['field']] ?? 'DATO DE DEMOSTRACIÓN')));
                                $biometricUrl = $biometricUrls[$element['field']] ?? null;

                                $rotation = (int) ($element['rotation'] ?? 0);
                                $isRotated = $rotation !== 0;
                                $width = (float) $element['width'];
                                $height = (float) $element['height'];
                                $spanWidth = $isRotated && $width > 0 ? number_format(($height / $width) * 100, 2, '.', '').'%' : '100%';
                                $spanHeight = $isRotated && $height > 0 ? number_format(($width / $height) * 100, 2, '.', '').'%' : '100%';
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
                                        <img class="credential-element-resource"
                                             style="width: 100%; height: 100%; object-fit: {{ $element['field'] === 'fotografia' ? 'cover' : 'contain' }}; {{ ! empty($element['border']) ? 'border: '.$element['border'].';' : '' }}"
                                             src="{{ $biometricUrl }}"
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
                                        <svg class="w-full h-3 text-slate-900" viewBox="0 0 100 20" preserveAspectRatio="none">
                                            <rect x="0" y="0" width="3" height="20" fill="currentColor"/>
                                            <rect x="5" y="0" width="2" height="20" fill="currentColor"/>
                                            <rect x="9" y="0" width="4" height="20" fill="currentColor"/>
                                            <rect x="15" y="0" width="1" height="20" fill="currentColor"/>
                                            <rect x="18" y="0" width="3" height="20" fill="currentColor"/>
                                            <rect x="23" y="0" width="2" height="20" fill="currentColor"/>
                                            <rect x="27" y="0" width="5" height="20" fill="currentColor"/>
                                            <rect x="34" y="0" width="2" height="20" fill="currentColor"/>
                                            <rect x="38" y="0" width="3" height="20" fill="currentColor"/>
                                            <rect x="43" y="0" width="1" height="20" fill="currentColor"/>
                                            <rect x="46" y="0" width="4" height="20" fill="currentColor"/>
                                            <rect x="52" y="0" width="2" height="20" fill="currentColor"/>
                                            <rect x="56" y="0" width="3" height="20" fill="currentColor"/>
                                            <rect x="61" y="0" width="2" height="20" fill="currentColor"/>
                                            <rect x="65" y="0" width="4" height="20" fill="currentColor"/>
                                            <rect x="71" y="0" width="1" height="20" fill="currentColor"/>
                                            <rect x="74" y="0" width="3" height="20" fill="currentColor"/>
                                            <rect x="79" y="0" width="2" height="20" fill="currentColor"/>
                                            <rect x="83" y="0" width="4" height="20" fill="currentColor"/>
                                            <rect x="89" y="0" width="2" height="20" fill="currentColor"/>
                                            <rect x="93" y="0" width="3" height="20" fill="currentColor"/>
                                            <rect x="98" y="0" width="2" height="20" fill="currentColor"/>
                                        </svg>
                                        <span class="font-mono text-[5pt] font-bold text-slate-900 tracking-wider">{{ $displayValue }}</span>
                                    </div>
                                @else
                                    <span style="position: absolute; top: 50%; left: 50%; width: {{ $spanWidth }}; height: {{ $spanHeight }}; transform: {{ $spanTransform }}; font-family: {{ $element['font_family'] ?? ($element['field'] === 'folio' || $element['field'] === 'cuip' ? 'monospace' : 'Inter, sans-serif') }}; font-size: {{ $element['font_size'] }}pt; display: flex; align-items: center; justify-content: {{ $element['align'] === 'left' ? 'flex-start' : ($element['align'] === 'right' ? 'flex-end' : 'center') }}; white-space: pre-line;">{{ $displayValue }}</span>
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
@endsection

@push('styles')
    <style>
        @page {
            size: 54mm 86mm;
            margin: 0;
        }
    </style>
@endpush
