@extends('layouts.app')

@section('title', 'Diseño de credencial')
@section('heading', 'Administración')
@section('subheading', 'Diseño de credencial · Editor de plantilla')

@section('content')
    @include('modules.devices._admin_tabs', ['active' => 'diseno-credencial'])

    <div id="credential-template-editor"
         class="template-editor"
         data-template-version="{{ $selectedTemplateVersion ?? 1 }}"
         data-save-url="{{ route('impresion.plantilla.guardar') }}"
         data-versions-url="{{ route('impresion.plantilla.versiones') }}?template_version={{ $selectedTemplateVersion ?? 1 }}"
         data-front-background="{{ asset('credentials/front.png') }}"
         data-rear-background="{{ asset('credentials/rear.png') }}"
         data-resource-upload-url="{{ route('impresion.plantilla.recurso.subir') }}"
         data-resource-base-url="{{ url('/impresion/plantilla/recursos') }}"
         data-restore-url="{{ url('/impresion/plantilla/restaurar') }}"
         data-approve-url="{{ url('/impresion/plantilla/aprobar') }}"
         data-view-versions="{{ (auth()->user()?->can('view-credential-template-versions') ?? false) ? 'true' : 'false' }}"
         data-manage="{{ auth()->user()?->can('manage-credential-templates') ? 'true' : 'false' }}"
         data-approve="{{ auth()->user()?->can('approve-credential-templates') ? 'true' : 'false' }}"
         data-is-saved="{{ $template ? 'true' : 'false' }}"
         data-current-version="{{ $template?->current_version ?? 0 }}"
         data-published-version="{{ $template?->published_version ?? 0 }}"
         data-template-status="{{ $template?->status ?? 'default' }}">
        <script id="credential-template-config" type="application/json">{!! json_encode($templateConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
        <script id="credential-template-defaults" type="application/json">{!! json_encode($defaultConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
        <script id="credential-template-labels" type="application/json">{!! json_encode($fieldLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
        <script id="credential-template-demo" type="application/json">{!! json_encode($demoData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>

        <div class="template-toolbar" role="toolbar" aria-label="Acciones del editor">
            <div class="template-toolbar-title">
                <div class="inline-flex items-center gap-2 mb-1.5">
                    <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Plantilla:</span>
                    <div class="inline-flex rounded-lg bg-slate-200/90 p-0.5 shadow-inner text-xs">
                        <a href="{{ route('impresion.plantilla', ['template_version' => 1]) }}"
                           class="template-version-switcher-link px-2.5 py-1 rounded-md transition-all {{ ($selectedTemplateVersion ?? 1) == 1 ? 'bg-white text-slate-900 font-bold shadow-xs' : 'text-slate-600 hover:text-slate-900 font-medium' }}"
                           title="Versión 1: Plantilla base institucional">
                            v1 Base
                        </a>
                        <a href="{{ route('impresion.plantilla', ['template_version' => 2]) }}"
                           class="template-version-switcher-link px-2.5 py-1 rounded-md transition-all {{ ($selectedTemplateVersion ?? 1) == 2 ? 'bg-inst-700 text-white font-bold shadow-xs' : 'text-slate-600 hover:text-slate-900 font-medium' }}"
                           title="Versión 2: Reproducción oficial real física (Arial, CardStudio)">
                            v2 Oficial Real
                        </a>
                    </div>
                </div>
                <label for="template-name" class="sr-only">Nombre de plantilla</label>
                <input id="template-name" maxlength="120" value="{{ $templateConfig['name'] }}" aria-label="Nombre de plantilla" class="template-title-input">
                <div class="template-status-row">
                    <span id="template-status-badge" class="template-status-badge">Plantilla predeterminada</span>
                    <span id="template-dirty-badge" class="template-dirty-badge" hidden>Cambios sin guardar</span>
                    <span class="template-demo-indicator">Demostración · v{{ $selectedTemplateVersion ?? 1 }}</span>
                </div>
            </div>

            <div class="template-toolbar-actions">
                <div class="template-button-group" aria-label="Historial">
                    <button id="template-undo" type="button" class="template-tool-button" aria-label="Deshacer" title="Deshacer (Ctrl+Z)" disabled>↶</button>
                    <button id="template-redo" type="button" class="template-tool-button" aria-label="Rehacer" title="Rehacer (Ctrl+Y)" disabled>↷</button>
                </div>
                <label class="template-face-control">
                    <span class="sr-only">Cara de la credencial</span>
                    <select id="template-face" aria-label="Cara de la credencial">
                        <option value="front">Anverso</option>
                        <option value="rear">Reverso</option>
                    </select>
                </label>
                <div class="template-button-group" aria-label="Zoom del lienzo">
                    <button id="template-zoom-out" type="button" class="template-tool-button" aria-label="Reducir zoom">−</button>
                    <span id="template-zoom-level" class="template-zoom-level" aria-live="polite">100%</span>
                    <button id="template-zoom-in" type="button" class="template-tool-button" aria-label="Aumentar zoom">+</button>
                    <button id="template-zoom-fit" type="button" class="template-tool-button template-fit-button">Ajustar</button>
                </div>
                <button id="template-grid-toggle" type="button" class="template-secondary-button" aria-pressed="false">Cuadrícula</button>
                <button id="template-guides-toggle" type="button" class="template-secondary-button" aria-pressed="true">Guías</button>
                <button id="template-preview" type="button" class="template-secondary-button">Vista previa</button>
                <button id="template-save" type="button" @disabled(! auth()->user()?->can('manage-credential-templates')) title="{{ auth()->user()?->can('manage-credential-templates') ? 'Guardar como una nueva versión borrador' : 'Requiere sesión y permiso de edición de plantillas' }}" class="template-primary-button">
                    Guardar borrador
                </button>
            </div>
        </div>

        <div class="template-demo-notice">
            <strong>Vista de demostración.</strong>
            <span>No consulta credenciales reales. Los fondos institucionales son inalterables; los datos y recursos ausentes se muestran como marcadores.</span>
            <a href="{{ route('impresion') }}">Volver a impresión</a>
        </div>

        <div class="template-workspace">
            <aside class="template-panel template-layers-panel" aria-label="Elementos y capas">
                <div class="template-panel-heading">
                    <div>
                        <h2>Elementos y capas</h2>
                        <p id="template-face-caption">Anverso · elementos de la plantilla</p>
                    </div>
                    <span id="template-layer-count" class="template-count-badge">0</span>
                </div>
                <div id="template-layer-list" class="template-layer-list"></div>
                <p class="template-panel-hint">Selecciona una capa para editarla. Usa las flechas para cambiar su orden de representación.</p>
            </aside>

            <section class="template-stage" aria-label="Lienzo de credencial">
                <div class="template-stage-heading">
                    <div>
                        <h2 id="template-stage-title">Anverso</h2>
                        <p>54 × 86 mm · las posiciones se guardan en coordenadas normalizadas</p>
                    </div>
                    <span class="template-drag-hint">Arrastra elementos desbloqueados o usa las flechas del teclado</span>
                </div>
                <div id="template-canvas-wrap" class="template-canvas-wrap">
                    <div id="credential-canvas" class="credential-canvas" aria-label="Lienzo de plantilla, 54 por 86 milímetros">
                        <img id="credential-background" class="credential-background" src="{{ asset('credentials/front.png') }}" alt="Fondo institucional inalterable">
                        <div id="credential-elements" class="credential-elements"></div>
                    </div>
                </div>
                <div class="template-stage-footer">
                    <span>Fondo bloqueado · tamaño físico 54 × 86 mm</span>
                    <span id="template-selection-hint">Selecciona una capa para ver sus propiedades</span>
                </div>
            </section>

            <aside class="template-panel template-properties-panel" aria-label="Propiedades y versiones">
                <section class="template-properties-section">
                    <div class="template-panel-heading template-property-heading">
                        <div>
                            <h2>Propiedades</h2>
                            <p id="template-element-type">Selecciona un elemento</p>
                        </div>
                        <button id="template-reset-element" type="button" class="template-icon-button" title="Restablecer propiedades del elemento" aria-label="Restablecer propiedades del elemento" disabled>↺</button>
                    </div>
                    <p id="template-selected-name" class="template-selected-name">Ningún elemento seleccionado</p>

                    <label id="template-kind-row" class="template-field-label template-kind-row">Tipo de contenido
                        <select id="template-kind" class="template-field-control">
                            <option value="static">Texto fijo</option>
                            <option value="dynamic">Dato dinámico</option>
                        </select>
                    </label>
                    <div class="template-property-grid">
                        <label class="template-field-label template-span-2">Dato de la credencial
                            <select id="template-field" class="template-field-control">
                                <option value="">Sin campo dinámico</option>
                                @foreach ($fieldLabels as $field => $label)
                                    <option value="{{ $field }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label id="template-label-row" class="template-field-label template-span-2">Etiqueta visible del campo
                            <input id="template-label" maxlength="120" class="template-field-control">
                        </label>
                        <label id="template-content-row" class="template-field-label template-span-2">Contenido fijo
                            <textarea id="template-content" rows="2" maxlength="1000" class="template-field-control" placeholder="Texto fijo de la plantilla"></textarea>
                        </label>
                    </div>

                    <fieldset class="template-property-fieldset">
                        <legend>Geometría</legend>
                        <div class="template-property-grid">
                            @foreach (['x' => 'Posición X (%)', 'y' => 'Posición Y (%)', 'width' => 'Ancho (%)', 'height' => 'Alto (%)'] as $key => $label)
                                <label class="template-field-label">{{ $label }}
                                    <input id="template-{{ $key }}" type="number" min="0" max="100" step="0.1" class="template-field-control">
                                </label>
                            @endforeach
                            <label class="template-field-label template-span-2">Rotación
                                <select id="template-rotation" class="template-field-control">
                                    <option value="0">Horizontal</option>
                                    <option value="-90">Vertical −90°</option>
                                    <option value="90">Vertical 90°</option>
                                    <option value="270">Vertical 270°</option>
                                </select>
                            </label>
                            <label class="template-field-label template-span-2">Borde (CSS)
                                <input id="template-border" list="template-border-presets" placeholder="ej. 0.5px solid #111827" class="template-field-control">
                                <datalist id="template-border-presets">
                                    <option value="">Ninguno</option>
                                    <option value="0.5px solid #111827">0.5px sólido negro</option>
                                    <option value="1px solid #111827">1px sólido negro</option>
                                    <option value="0.5px solid #9ca3af">0.5px sólido gris</option>
                                    <option value="1px solid #9ca3af">1px sólido gris</option>
                                    <option value="0.5px dashed #111827">0.5px punteado</option>
                                </datalist>
                            </label>
                        </div>
                    </fieldset>

                    <fieldset id="template-text-properties" class="template-property-fieldset">
                        <legend>Texto</legend>
                        <div class="template-property-grid">
                            <label class="template-field-label">Tamaño (pt)
                                <input id="template-font-size" type="number" min="3" max="36" step="0.5" class="template-field-control">
                            </label>
                            <label class="template-field-label">Interlineado
                                <input id="template-line-height" type="number" min="0.8" max="2" step="0.05" class="template-field-control">
                            </label>
                            <label class="template-field-label">Tipografía
                                <select id="template-font-family" class="template-field-control">
                                    <option value="Inter">Inter</option>
                                    <option value="Arial">Arial</option>
                                    <option value="Arial, Helvetica, sans-serif">Arial institucional</option>
                                    <option value="sans-serif">Sans serif</option>
                                </select>
                            </label>
                            <label class="template-field-label">Grosor
                                <select id="template-weight" class="template-field-control">
                                    <option value="400">Regular</option>
                                    <option value="600">Seminegrita</option>
                                    <option value="700">Negrita</option>
                                    <option value="800">Extra negrita</option>
                                </select>
                            </label>
                            <label class="template-field-label">Alineación
                                <select id="template-align" class="template-field-control">
                                    <option value="left">Izquierda</option>
                                    <option value="center">Centro</option>
                                    <option value="right">Derecha</option>
                                </select>
                            </label>
                            <label class="template-field-label">Color
                                <input id="template-color" type="color" class="template-color-control">
                            </label>
                            <label class="template-field-label template-span-2">Texto que excede el área
                                <span class="template-static-control">Se ajusta al ancho y alto disponibles</span>
                            </label>
                        </div>
                    </fieldset>

                    <fieldset id="template-image-properties" class="template-property-fieldset" hidden>
                        <legend>Recurso gráfico</legend>
                        <p id="template-resource-status" class="template-field-help">No hay un recurso cargado; se mostrará el marcador de demostración.</p>
                        <label class="template-field-label">Sustituir imagen (PNG, JPG o WebP; máximo 2 MB)
                            <input id="template-resource" type="file" accept="image/png,image/jpeg,image/webp" @disabled(! auth()->user()?->can('manage-credential-templates')) class="template-resource-control disabled:cursor-not-allowed disabled:opacity-50">
                        </label>
                        <label id="template-lock-aspect-row" class="template-checkbox-row" hidden>
                            <input id="template-lock-aspect" type="checkbox">
                            Conservar proporción al cambiar dimensiones
                        </label>
                        <p class="template-field-help">El encuadre usa la imagen completa (contener); no se recorta.</p>
                    </fieldset>

                    <fieldset class="template-property-fieldset">
                        <legend>Estado de capa</legend>
                        <label class="template-checkbox-row"><input id="template-visible" type="checkbox"> Visible en el diseño</label>
                        <label class="template-checkbox-row"><input id="template-locked" type="checkbox"> Bloquear movimiento en el lienzo</label>
                        <label id="template-optional-row" class="template-checkbox-row"><input id="template-optional" type="checkbox"> Elemento opcional</label>
                    </fieldset>
                </section>

                <section class="template-version-section">
                    <div class="template-panel-heading">
                        <div>
                            <h2>Versiones</h2>
                            <p id="template-version-status">La aprobación no se modifica al guardar un borrador.</p>
                        </div>
                    </div>
                    <label for="template-version" class="template-field-label">Versiones guardadas</label>
                    <select id="template-version" class="template-field-control" @disabled(! (auth()->user()?->can('view-credential-template-versions') ?? false))>
                        <option value="">Cargando versiones…</option>
                    </select>
                    <p id="template-version-details" class="template-field-help">Selecciona una versión para consultar su estado.</p>
                    <label for="template-summary" class="template-field-label">Resumen del cambio (opcional)
                        <input id="template-summary" maxlength="255" class="template-field-control">
                    </label>
                    <div class="template-version-actions">
                        <button id="template-restore" type="button" @disabled(! auth()->user()?->can('manage-credential-templates')) class="template-secondary-button" title="{{ auth()->user()?->can('manage-credential-templates') ? 'Restaurar como una nueva versión borrador' : 'Requiere permiso de edición de plantillas' }}">Restaurar como borrador</button>
                        <button id="template-approve" type="button" @disabled(! auth()->user()?->can('approve-credential-templates')) class="template-approve-button" title="{{ auth()->user()?->can('approve-credential-templates') ? 'Aprobar la versión seleccionada' : 'Requiere permiso de aprobación' }}">Aprobar versión</button>
                    </div>
                    <div class="template-version-actions template-recovery-actions">
                        <button id="template-cancel" type="button" class="template-secondary-button">Cancelar cambios</button>
                        <button id="template-reset" type="button" class="template-secondary-button">Restablecer predeterminada</button>
                    </div>
                    <a id="template-print-link" href="{{ route('impresion.plantilla.imprimir', ['template_version' => $selectedTemplateVersion ?? 1]) }}" @if (! $template?->published_config || $template->published_config['width_mm'] === null || $template->published_config['height_mm'] === null) hidden @endif class="template-print-link">Abrir impresión de demostración aprobada</a>
                    <span id="template-print-disabled" @if ($template?->published_config && $template->published_config['width_mm'] !== null && $template->published_config['height_mm'] !== null) hidden @endif class="template-print-disabled">Impresión aprobada no disponible: aún no hay una versión publicada.</span>
                </section>
            </aside>
        </div>

        <div id="template-message" class="template-live-message hidden" role="status" aria-live="polite"></div>

        <dialog id="template-preview-dialog" class="template-preview-dialog" aria-labelledby="template-preview-title">
            <div class="template-preview-header">
                <div>
                    <h2 id="template-preview-title">Vista previa de demostración</h2>
                    <p>Solo visualización · los datos personales reales no están conectados</p>
                </div>
                <button id="template-preview-close" type="button" class="template-icon-button" aria-label="Cerrar vista previa">×</button>
            </div>
            <div class="template-preview-faces">
                @foreach (['front' => 'Anverso', 'rear' => 'Reverso'] as $face => $label)
                    <section class="template-preview-face">
                        <h3>{{ $label }}</h3>
                        <div class="template-preview-card" data-preview-face="{{ $face }}">
                            <img class="credential-background" src="{{ asset('credentials/'.$face.'.png') }}" alt="">
                            <div class="credential-elements"></div>
                        </div>
                    </section>
                @endforeach
            </div>
        </dialog>
    </div>
@endsection
