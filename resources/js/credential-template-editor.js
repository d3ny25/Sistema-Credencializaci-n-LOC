const fitCredentialText = (container, baseSize) => {
    const text = container.querySelector('span');
    if (!text) {
        return;
    }

    let size = Number(baseSize);
    text.style.fontSize = `${size}pt`;

    while (
        size > 3
        && (text.scrollWidth > text.clientWidth + 1 || text.scrollHeight > text.clientHeight + 1)
    ) {
        size = Math.max(3, size - 0.5);
        text.style.fontSize = `${size}pt`;
    }
};

const editor = document.querySelector('#credential-template-editor');

if (editor) {
    const parseJson = (id) => JSON.parse(document.querySelector(`#${id}`).textContent);
    const config = parseJson('credential-template-config');
    const defaults = parseJson('credential-template-defaults');
    const fieldLabels = parseJson('credential-template-labels');
    const demoData = parseJson('credential-template-demo');
    const canvas = document.querySelector('#credential-canvas');
    const canvasWrap = document.querySelector('#template-canvas-wrap');
    const elementsHost = document.querySelector('#credential-elements');
    const background = document.querySelector('#credential-background');
    const faceSelect = document.querySelector('#template-face');
    const layerList = document.querySelector('#template-layer-list');
    const versionSelect = document.querySelector('#template-version');
    const message = document.querySelector('#template-message');
    const resourceInput = document.querySelector('#template-resource');
    const initialSnapshot = JSON.stringify(config);
    let savedSnapshot = initialSnapshot;
    let history = [initialSnapshot];
    let historyIndex = 0;
    let selectedId = null;
    let activeDrag = null;
    let zoom = 1;
    let fitZoom = true;
    let currentVersions = [];

    const property = (id) => document.querySelector(`#${id}`);
    const activeFace = () => config.faces[faceSelect.value];
    const selectedElement = () => activeFace().elements.find((element) => element.id === selectedId);
    const isDirty = () => JSON.stringify(config) !== savedSnapshot;
    const elementType = (element) => ({
        static: 'Texto fijo',
        dynamic: 'Dato dinámico',
        image: 'Imagen o recurso',
        barcode: 'Elemento codificado',
    }[element.kind] ?? 'Elemento');

    const normalize = (template) => {
        template.width_mm = 54;
        template.height_mm = 86;
        for (const face of Object.values(template.faces)) {
            face.elements = face.elements.map((element) => {
                const kind = element.kind
                    ?? (element.id === 'leyenda_acredita' || element.field === 'texto_fijo' ? 'static'
                        : element.field === 'codigo_barras' ? 'barcode'
                            : ['fotografia', 'huella_izquierda', 'huella_derecha', 'firma_persona', 'firma_autorizada', 'firma_secretario', 'firma_oficial_mayor', 'firma_titular'].includes(element.field) ? 'image'
                                : 'dynamic');
                const normalizedElement = {
                    kind,
                    label: null,
                    content: element.id === 'leyenda_acredita' ? 'Se acredita a:' : null,
                    line_height: 1,
                    font_family: 'Inter',
                    asset_path: null,
                    image_aspect: null,
                    locked: false,
                    border: null,
                    ...element,
                    ...(element.id === 'leyenda_acredita' ? { field: 'texto_fijo' } : {}),
                };
                delete normalizedElement.parameter;

                return normalizedElement;
            });
        }
        return template;
    };

    normalize(config);
    normalize(defaults);
    savedSnapshot = JSON.stringify(config);
    history = [savedSnapshot];

    const showMessage = (text, isError = false) => {
        message.textContent = text;
        message.classList.remove('hidden', 'template-message-error', 'template-message-success');
        message.classList.add(isError ? 'template-message-error' : 'template-message-success');
    };

    const refreshHistoryButtons = () => {
        property('template-undo').disabled = historyIndex <= 0;
        property('template-redo').disabled = historyIndex >= history.length - 1;
    };

    const refreshStatus = () => {
        const dirty = isDirty();
        const dirtyBadge = property('template-dirty-badge');
        dirtyBadge.hidden = !dirty;
        property('template-status-badge').textContent = dirty
            ? 'Borrador en edición'
            : Number(editor.dataset.publishedVersion) > 0
                ? `Versión aprobada v${editor.dataset.publishedVersion}`
                : editor.dataset.isSaved === 'true'
                    ? `Borrador v${editor.dataset.currentVersion}`
                    : 'Plantilla predeterminada';
        property('template-version-status').textContent = dirty
            ? 'Hay modificaciones sin guardar; publicar no es automático.'
            : Number(editor.dataset.publishedVersion) > 0
                ? `Última versión aprobada: v${editor.dataset.publishedVersion}.`
                : 'La aprobación no se modifica al guardar un borrador.';
        property('template-print-link').hidden = Number(editor.dataset.publishedVersion) <= 0;
        property('template-print-disabled').hidden = Number(editor.dataset.publishedVersion) > 0;
        window.onbeforeunload = dirty
            ? (event) => {
                event.preventDefault();
                event.returnValue = '';
                return '';
            }
            : null;
    };

    const pushHistory = () => {
        const snapshot = JSON.stringify(config);
        if (snapshot === history[historyIndex]) {
            return;
        }
        history = history.slice(0, historyIndex + 1);
        history.push(snapshot);
        if (history.length > 80) {
            history.shift();
        }
        historyIndex = history.length - 1;
        refreshHistoryButtons();
        refreshStatus();
    };

    const replaceConfig = (snapshot) => {
        const nextConfig = normalize(JSON.parse(snapshot));
        Object.keys(config).forEach((key) => delete config[key]);
        Object.assign(config, nextConfig);
    };

    const moveHistory = (direction) => {
        const nextIndex = historyIndex + direction;
        if (nextIndex < 0 || nextIndex >= history.length) {
            return;
        }
        historyIndex = nextIndex;
        replaceConfig(history[historyIndex]);
        property('template-name').value = config.name;
        renderPreview();
        refreshHistoryButtons();
    };

    const elementText = (element) => {
        if (element.kind === 'static') {
            return element.content ?? '';
        }
        if (element.kind === 'barcode') {
            return element.content ?? 'CÓDIGO DE BARRAS · PENDIENTE';
        }
        const value = demoData[element.field] === null || demoData[element.field] === undefined || demoData[element.field] === ''
            ? 'DATO PENDIENTE'
            : demoData[element.field];
        return element.label ? `${element.label}\n${value}` : value;
    };

    const applyElementStyle = (node, element, order, isSelected = false) => {
        const text = node.querySelector('span');
        const image = node.querySelector('img');
        const isRotated = Number(element.rotation) !== 0;
        node.style.left = `${element.x}%`;
        node.style.top = `${element.y}%`;
        node.style.width = `${element.width}%`;
        node.style.height = `${element.height}%`;
        node.style.zIndex = String(order + 1);
        node.style.border = element.border ?? '';
        node.style.boxSizing = 'border-box';
        text.style.width = isRotated ? `${(element.height / element.width) * 100}%` : '100%';
        text.style.height = isRotated ? `${(element.width / element.height) * 100}%` : '100%';
        text.style.fontFamily = element.font_family ?? 'Inter';
        text.style.fontWeight = element.weight;
        text.style.color = element.color;
        text.style.textAlign = element.align;
        text.style.lineHeight = element.line_height ?? 1;
        text.style.transform = `translate(-50%, -50%) rotate(${element.rotation}deg)`;
        text.hidden = Boolean(image && element.asset_path);
        if (image) {
            image.hidden = !element.asset_path;
            if (element.asset_path) {
                image.src = `${editor.dataset.resourceBaseUrl}/${encodeURIComponent(element.asset_path)}`;
            } else {
                image.removeAttribute('src');
            }
        }
        node.hidden = !element.visible;
        node.classList.toggle('credential-element-selected', isSelected);
        node.classList.toggle('credential-element-locked', Boolean(element.locked));
        node.classList.toggle('credential-demo-image', element.kind === 'image');
        node.classList.toggle('credential-demo-barcode', element.kind === 'barcode' && !element.asset_path);
        fitCredentialText(node, element.font_size);
    };

    const makeElementNode = (element, order, interactive) => {
        const node = document.createElement('div');
        node.className = interactive ? 'credential-element' : 'credential-preview-element';
        node.dataset.elementId = element.id;
        node.title = `${element.id} · ${fieldLabels[element.field] ?? elementType(element)}`;
        if (interactive) {
            node.tabIndex = 0;
            node.setAttribute('role', 'button');
            node.setAttribute('aria-label', `${element.id}, ${elementType(element)}${element.locked ? ', bloqueado' : ''}`);
        }

        const image = document.createElement('img');
        image.alt = element.content ?? '';
        image.hidden = true;
        const text = document.createElement('span');
        text.textContent = elementText(element);
        node.append(image, text);
        applyElementStyle(node, element, order, interactive && element.id === selectedId);
        return node;
    };

    const renderLayers = () => {
        const elements = activeFace().elements;
        const groups = [
            { kind: 'static', label: 'Texto fijo' },
            { kind: 'dynamic', label: 'Campos dinámicos' },
            { kind: 'image', label: 'Imágenes y recursos' },
            { kind: 'barcode', label: 'Elementos codificados' },
        ];
        layerList.replaceChildren();
        property('template-layer-count').textContent = String(elements.length);
        property('template-face-caption').textContent = `${faceSelect.value === 'front' ? 'Anverso' : 'Reverso'} · elementos de la plantilla`;

        for (const group of groups) {
            const groupElements = elements
                .map((element, index) => ({ element, index }))
                .filter(({ element }) => element.kind === group.kind)
                .reverse();
            if (groupElements.length === 0) {
                continue;
            }

            const section = document.createElement('section');
            section.className = 'template-layer-group';
            const heading = document.createElement('h3');
            heading.textContent = group.label;
            section.append(heading);

            for (const { element, index } of groupElements) {
                const row = document.createElement('div');
                row.className = 'template-layer-row';
                row.classList.toggle('template-layer-row-selected', element.id === selectedId);
                row.classList.toggle('template-layer-row-hidden', !element.visible);
                row.dataset.layerId = element.id;

                const select = document.createElement('button');
                select.type = 'button';
                select.className = 'template-layer-select';
                select.dataset.action = 'select';
                select.dataset.elementId = element.id;
                select.setAttribute('aria-pressed', element.id === selectedId ? 'true' : 'false');
                const name = document.createElement('span');
                name.className = 'template-layer-name';
                name.textContent = element.id.replaceAll('_', ' ');
                const type = document.createElement('span');
                type.className = 'template-layer-type';
                type.textContent = `Capa ${index + 1} · ${element.visible ? (element.locked ? 'Bloqueado' : elementType(element)) : 'Oculto'}`;
                select.append(name, type);

                const controls = document.createElement('div');
                controls.className = 'template-layer-controls';
                for (const [action, label, disabled] of [
                    ['raise', 'Subir capa', index >= elements.length - 1],
                    ['lower', 'Bajar capa', index <= 0],
                    ['visibility', element.visible ? 'Ocultar capa' : 'Mostrar capa', false],
                    ['lock', element.locked ? 'Desbloquear capa' : 'Bloquear capa', false],
                ]) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.dataset.action = action;
                    button.dataset.elementId = element.id;
                    button.disabled = disabled;
                    button.title = label;
                    button.setAttribute('aria-label', label);
                    if (action === 'visibility') {
                        button.setAttribute('aria-pressed', element.visible ? 'true' : 'false');
                    } else if (action === 'lock') {
                        button.setAttribute('aria-pressed', element.locked ? 'true' : 'false');
                    }
                    button.textContent = ({
                        raise: '↑',
                        lower: '↓',
                        visibility: element.visible ? '◉' : '○',
                        lock: element.locked ? '▣' : '□',
                    })[action];
                    controls.append(button);
                }
                row.append(select, controls);
                section.append(row);
            }
            layerList.append(section);
        }
    };

    const syncControls = () => {
        const element = selectedElement();
        const hasElement = Boolean(element);
        property('template-selected-name').textContent = element
            ? element.id.replaceAll('_', ' ')
            : 'Ningún elemento seleccionado';
        property('template-element-type').textContent = element ? elementType(element) : 'Selecciona una capa para editar';
        property('template-selection-hint').textContent = element
            ? `${element.id} · ${elementType(element)}${element.locked ? ' · bloqueado' : ''}`
            : 'Selecciona una capa para ver sus propiedades';
        property('template-reset-element').disabled = !hasElement;

        for (const id of [
            'template-field', 'template-label', 'template-content',
            'template-x', 'template-y', 'template-width', 'template-height',
            'template-rotation', 'template-border', 'template-font-size', 'template-line-height',
            'template-font-family', 'template-weight', 'template-align', 'template-color',
            'template-visible', 'template-locked', 'template-optional',
        ]) {
            if (property(id)) {
                property(id).disabled = !hasElement;
            }
        }
        if (!element) {
            return;
        }

        property('template-field').value = element.field ?? '';
        property('template-field').disabled = element.kind !== 'dynamic';
        property('template-kind-row').hidden = !['static', 'dynamic'].includes(element.kind);
        property('template-kind').value = element.kind;
        property('template-label').value = element.label ?? '';
        property('template-label').disabled = element.kind !== 'dynamic';
        property('template-label-row').hidden = element.kind !== 'dynamic';
        property('template-content').value = element.content ?? '';
        property('template-content').disabled = !['static', 'barcode'].includes(element.kind);
        property('template-content-row').hidden = !['static', 'barcode'].includes(element.kind);
        property('template-text-properties').hidden = element.kind === 'image';
        property('template-image-properties').hidden = element.kind !== 'image';

        for (const key of ['x', 'y', 'width', 'height']) {
            property(`template-${key}`).value = element[key];
        }
        property('template-rotation').value = element.rotation;
        if (property('template-border')) {
            property('template-border').value = element.border ?? '';
        }
        property('template-font-size').value = element.font_size;
        property('template-line-height').value = element.line_height ?? 1;
        property('template-font-family').value = element.font_family ?? 'Inter';
        property('template-weight').value = element.weight;
        property('template-align').value = element.align;
        property('template-color').value = element.color;
        property('template-visible').checked = Boolean(element.visible);
        property('template-locked').checked = Boolean(element.locked);
        property('template-optional').checked = Boolean(element.optional);
        property('template-optional-row').hidden = false;
        property('template-x').disabled = Boolean(element.locked);
        property('template-y').disabled = Boolean(element.locked);
        property('template-resource-status').textContent = element.asset_path
            ? 'Hay una imagen cargada en esta versión de la plantilla.'
            : 'No hay un recurso cargado; se mostrará el marcador de demostración.';
        property('template-lock-aspect').checked = Boolean(element.image_aspect);
        property('template-lock-aspect-row').hidden = !element.image_aspect;
        resourceInput.value = '';
        resourceInput.disabled = element.kind !== 'image' || editor.dataset.manage !== 'true';
    };

    const updateZoom = () => {
        const availableWidth = Math.max(180, canvasWrap.clientWidth - 36);
        const availableHeight = Math.max(260, document.querySelector('.template-stage').clientHeight - 132);
        const fitWidth = Math.min(360, availableWidth, availableHeight / (1017 / 639));
        if (fitZoom) {
            zoom = Math.max(0.35, Math.min(1, fitWidth / 360));
        }
        canvas.style.width = `${360 * zoom}px`;
        property('template-zoom-level').textContent = `${Math.round(zoom * 100)}%`;
    };

    const renderPreview = () => {
        const faceName = faceSelect.value;
        const elements = activeFace().elements;
        if (!elements.some((element) => element.id === selectedId)) {
            selectedId = elements.find((element) => element.visible)?.id ?? elements[0]?.id ?? null;
        }
        background.src = faceName === 'front' ? editor.dataset.frontBackground : editor.dataset.rearBackground;
        elementsHost.replaceChildren();
        elements.forEach((element, index) => {
            elementsHost.append(makeElementNode(element, index, true));
        });
        property('template-stage-title').textContent = faceName === 'front' ? 'Anverso' : 'Reverso';
        renderLayers();
        syncControls();
        updateZoom();
        refreshStatus();
    };

    const updateSelected = (key, value) => {
        const element = selectedElement();
        if (!element || element.locked && ['x', 'y'].includes(key)) {
            return;
        }
        element[key] = value;
        pushHistory();
        renderPreview();
    };

    const updateSelectedText = (key, value) => {
        const element = selectedElement();
        if (!element) {
            return;
        }
        element[key] = value;
        pushHistory();

        const index = activeFace().elements.indexOf(element);
        const node = elementsHost.querySelector(`[data-element-id="${CSS.escape(element.id)}"]`);
        if (node) {
            node.querySelector('span').textContent = elementText(element);
            applyElementStyle(node, element, index, true);
        }
    };

    const setSelectedElement = (id) => {
        selectedId = id;
        renderPreview();
    };

    const moveLayer = (id, step) => {
        const elements = activeFace().elements;
        const index = elements.findIndex((element) => element.id === id);
        const nextIndex = index + step;
        if (index < 0 || nextIndex < 0 || nextIndex >= elements.length) {
            return;
        }
        [elements[index], elements[nextIndex]] = [elements[nextIndex], elements[index]];
        selectedId = id;
        pushHistory();
        renderPreview();
    };

    const nudgeElement = (element, deltaX, deltaY) => {
        if (element.locked) {
            return;
        }
        element.x = Math.round(Math.max(0, Math.min(100 - Number(element.width), Number(element.x) + deltaX)) * 10) / 10;
        element.y = Math.round(Math.max(0, Math.min(100 - Number(element.height), Number(element.y) + deltaY)) * 10) / 10;
        pushHistory();
        renderPreview();
    };

    const renderModalPreview = () => {
        document.querySelectorAll('[data-preview-face]').forEach((card) => {
            const face = config.faces[card.dataset.previewFace];
            const host = card.querySelector('.credential-elements');
            host.replaceChildren();
            face.elements.forEach((element, index) => {
                if (element.visible) {
                    host.append(makeElementNode(element, index, false));
                }
            });
        });
    };

    faceSelect.addEventListener('change', () => {
        selectedId = null;
        renderPreview();
    });

    layerList.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button || button.disabled) {
            return;
        }
        const element = activeFace().elements.find((candidate) => candidate.id === button.dataset.elementId);
        if (!element) {
            return;
        }
        switch (button.dataset.action) {
            case 'select':
                setSelectedElement(element.id);
                break;
            case 'raise':
                moveLayer(element.id, 1);
                break;
            case 'lower':
                moveLayer(element.id, -1);
                break;
            case 'visibility':
                element.visible = !element.visible;
                selectedId = element.id;
                pushHistory();
                renderPreview();
                break;
            case 'lock':
                element.locked = !element.locked;
                selectedId = element.id;
                pushHistory();
                renderPreview();
                break;
        }
    });

    property('template-field').addEventListener('change', (event) => updateSelected('field', event.target.value || null));
    property('template-kind').addEventListener('change', (event) => {
        const element = selectedElement();
        if (!element || !['static', 'dynamic'].includes(element.kind)) {
            return;
        }
        if (event.target.value === 'static') {
            element.kind = 'static';
            element.field = 'texto_fijo';
            element.label = null;
            element.content = element.content ?? '';
        } else {
            element.kind = 'dynamic';
            element.field = null;
            element.label = null;
            element.content = null;
        }
        pushHistory();
        renderPreview();
        if (element.kind === 'dynamic') {
            property('template-field').focus();
            showMessage('Selecciona el dato del sistema que alimentará este elemento.');
        }
    });
    property('template-label').addEventListener('input', (event) => updateSelectedText('label', event.target.value || null));
    property('template-content').addEventListener('input', (event) => updateSelectedText('content', event.target.value || null));

    for (const key of ['x', 'y', 'width', 'height']) {
        property(`template-${key}`).addEventListener('change', (event) => {
            const value = Number(event.target.value);
            const element = selectedElement();
            if (!Number.isFinite(value) || value <= 0 || value > 100 || !element) {
                syncControls();
                return;
            }
            if (element.locked && (key === 'x' || key === 'y')) {
                syncControls();
                return;
            }
            if ((key === 'x' && value + Number(element.width) > 100) || (key === 'y' && value + Number(element.height) > 100)) {
                showMessage('El elemento debe permanecer dentro de los límites de la credencial.', true);
                syncControls();
                return;
            }

            const previousWidth = Number(element.width);
            const previousHeight = Number(element.height);
            const previousAspect = Number(element.image_aspect);
            if (key === 'width' && property('template-lock-aspect').checked && previousAspect > 0) {
                element.height = Math.round((value * (Number(config.width_mm) / Number(config.height_mm)) / previousAspect) * 1000) / 1000;
            } else if (key === 'height' && property('template-lock-aspect').checked && previousAspect > 0) {
                element.width = Math.round((value * previousAspect / (Number(config.width_mm) / Number(config.height_mm))) * 1000) / 1000;
            }
            if (Number(element.x) + Number(element.width) > 100 || Number(element.y) + Number(element.height) > 100) {
                element.width = previousWidth;
                element.height = previousHeight;
                showMessage('Las dimensiones deben quedar dentro de la credencial.', true);
                syncControls();
                return;
            }
            element[key] = value;
            pushHistory();
            renderPreview();
        });
    }

    for (const [id, key, read] of [
        ['template-rotation', 'rotation', (control) => Number(control.value)],
        ['template-border', 'border', (control) => control.value.trim() || null],
        ['template-font-size', 'font_size', (control) => Number(control.value)],
        ['template-line-height', 'line_height', (control) => Number(control.value)],
        ['template-font-family', 'font_family', (control) => control.value],
        ['template-weight', 'weight', (control) => Number(control.value)],
        ['template-align', 'align', (control) => control.value],
        ['template-color', 'color', (control) => control.value],
        ['template-visible', 'visible', (control) => control.checked],
        ['template-locked', 'locked', (control) => control.checked],
        ['template-optional', 'optional', (control) => control.checked],
    ]) {
        if (property(id)) {
            property(id).addEventListener('change', (event) => updateSelected(key, read(event.target)));
        }
    }

    property('template-border')?.addEventListener('input', (event) => {
        updateSelected('border', event.target.value.trim() || null);
    });

    property('template-name').addEventListener('input', (event) => {
        config.name = event.target.value;
        pushHistory();
    });

    property('template-lock-aspect').addEventListener('change', () => {
        const element = selectedElement();
        if (!element) {
            return;
        }
        if (property('template-lock-aspect').checked && !element.image_aspect) {
            showMessage('Carga una imagen para calcular y conservar su proporción.', true);
            property('template-lock-aspect').checked = false;
            return;
        }
        property('template-lock-aspect-row').hidden = !property('template-lock-aspect').checked;
    });

    property('template-reset-element').addEventListener('click', () => {
        const element = selectedElement();
        const defaultElement = defaults.faces[faceSelect.value].elements.find((candidate) => candidate.id === selectedId);
        if (!element || !defaultElement || !window.confirm('¿Restablecer las propiedades de este elemento a sus valores predeterminados?')) {
            return;
        }
        const index = activeFace().elements.indexOf(element);
        activeFace().elements[index] = structuredClone(defaultElement);
        pushHistory();
        renderPreview();
        showMessage('Se restablecieron las propiedades del elemento.');
    });

    property('template-undo').addEventListener('click', () => moveHistory(-1));
    property('template-redo').addEventListener('click', () => moveHistory(1));
    window.addEventListener('keydown', (event) => {
        const editing = event.target.matches('input, textarea, select, [contenteditable="true"]');
        if ((event.ctrlKey || event.metaKey) && !editing && event.key.toLowerCase() === 'z') {
            event.preventDefault();
            moveHistory(event.shiftKey ? 1 : -1);
        } else if ((event.ctrlKey || event.metaKey) && !editing && event.key.toLowerCase() === 'y') {
            event.preventDefault();
            moveHistory(1);
        }
    });

    property('template-zoom-out').addEventListener('click', () => {
        fitZoom = false;
        zoom = Math.max(0.35, Math.round((zoom - 0.1) * 10) / 10);
        updateZoom();
    });
    property('template-zoom-in').addEventListener('click', () => {
        fitZoom = false;
        zoom = Math.min(2, Math.round((zoom + 0.1) * 10) / 10);
        updateZoom();
    });
    property('template-zoom-fit').addEventListener('click', () => {
        fitZoom = true;
        updateZoom();
    });
    property('template-grid-toggle').addEventListener('click', (event) => {
        const enabled = event.currentTarget.getAttribute('aria-pressed') !== 'true';
        event.currentTarget.setAttribute('aria-pressed', String(enabled));
        canvas.classList.toggle('credential-canvas-grid', enabled);
    });
    property('template-guides-toggle').addEventListener('click', (event) => {
        const enabled = event.currentTarget.getAttribute('aria-pressed') !== 'true';
        event.currentTarget.setAttribute('aria-pressed', String(enabled));
        canvas.classList.toggle('credential-canvas-guides', enabled);
    });
    property('template-preview').addEventListener('click', () => {
        renderModalPreview();
        property('template-preview-dialog').showModal();
    });
    property('template-preview-close').addEventListener('click', () => property('template-preview-dialog').close());

    canvas.addEventListener('pointerdown', (event) => {
        if (event.button !== 0) {
            return;
        }
        const node = event.target.closest('[data-element-id]');
        if (!node || node.hidden) {
            return;
        }
        selectedId = node.dataset.elementId;
        renderPreview();
        const element = selectedElement();
        const selectedNode = elementsHost.querySelector(`[data-element-id="${CSS.escape(selectedId)}"]`);
        if (!element || element.locked) {
            selectedNode.focus({ preventScroll: true });
            return;
        }
        const rect = canvas.getBoundingClientRect();
        activeDrag = {
            node: selectedNode,
            rect,
            pointerId: event.pointerId,
            offsetX: event.clientX,
            offsetY: event.clientY,
            x: Number(element.x),
            y: Number(element.y),
            moved: false,
        };
        selectedNode.setPointerCapture(event.pointerId);
        selectedNode.classList.add('credential-element-dragging');
    });

    canvas.addEventListener('pointermove', (event) => {
        if (!activeDrag || event.pointerId !== activeDrag.pointerId) {
            return;
        }
        const element = selectedElement();
        const x = Math.max(0, Math.min(100 - Number(element.width), activeDrag.x + ((event.clientX - activeDrag.offsetX) / activeDrag.rect.width) * 100));
        const y = Math.max(0, Math.min(100 - Number(element.height), activeDrag.y + ((event.clientY - activeDrag.offsetY) / activeDrag.rect.height) * 100));
        element.x = Math.round(x * 10) / 10;
        element.y = Math.round(y * 10) / 10;
        activeDrag.moved = activeDrag.moved || Math.abs(event.clientX - activeDrag.offsetX) + Math.abs(event.clientY - activeDrag.offsetY) > 1;
        activeDrag.node.style.left = `${element.x}%`;
        activeDrag.node.style.top = `${element.y}%`;
        property('template-x').value = element.x;
        property('template-y').value = element.y;
    });

    const finishDrag = (event) => {
        if (!activeDrag || event.pointerId !== activeDrag.pointerId) {
            return;
        }
        activeDrag.node.classList.remove('credential-element-dragging');
        const moved = activeDrag.moved;
        activeDrag = null;
        if (moved) {
            pushHistory();
        }
        renderPreview();
    };

    canvas.addEventListener('pointerup', finishDrag);
    canvas.addEventListener('pointercancel', finishDrag);
    canvas.addEventListener('keydown', (event) => {
        if (!['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(event.key)) {
            return;
        }
        const element = selectedElement();
        if (!element || element.locked) {
            return;
        }
        event.preventDefault();
        const step = event.shiftKey ? 1 : 0.1;
        const deltaX = event.key === 'ArrowLeft' ? -step : event.key === 'ArrowRight' ? step : 0;
        const deltaY = event.key === 'ArrowUp' ? -step : event.key === 'ArrowDown' ? step : 0;
        nudgeElement(element, deltaX, deltaY);
        elementsHost.querySelector(`[data-element-id="${CSS.escape(selectedId)}"]`)?.focus({ preventScroll: true });
    });

    const requestJson = async (url, options = {}) => {
        const isForm = options.body instanceof FormData;
        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                ...(isForm ? {} : { 'Content-Type': 'application/json' }),
                ...options.headers,
            },
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const errors = Object.values(payload.errors ?? {}).flat();
            throw new Error(errors[0] ?? payload.message ?? `La solicitud falló (${response.status}).`);
        }
        return payload;
    };

    const renderVersionDetails = () => {
        const version = currentVersions.find((item) => String(item.id) === versionSelect.value);
        property('template-version-details').textContent = version
            ? `v${version.version} · ${version.approved ? 'Aprobada' : 'Borrador'} · ${version.created_at ? new Date(version.created_at).toLocaleString() : 'fecha no disponible'} · usuario ${version.created_by ?? 'no disponible'}${version.summary ? ` · ${version.summary}` : ''}`
            : 'Selecciona una versión para consultar su estado.';
    };

    const loadVersions = async () => {
        versionSelect.replaceChildren(new Option('Sin versiones guardadas', ''));
        currentVersions = [];
        renderVersionDetails();
        if (editor.dataset.viewVersions !== 'true') {
            return;
        }

        try {
            const payload = await requestJson(editor.dataset.versionsUrl, { method: 'GET' });
            currentVersions = payload.versions;
            versionSelect.replaceChildren(...currentVersions.map((version) => new Option(
                `v${version.version} · ${version.approved ? 'Aprobada' : 'Borrador'}${version.summary ? ` — ${version.summary}` : ''}`,
                version.id,
            )));
            if (currentVersions.length === 0) {
                versionSelect.add(new Option('Sin versiones guardadas', ''));
            }
            renderVersionDetails();
        } catch (error) {
            showMessage(error.message, true);
        }
    };

    versionSelect.addEventListener('change', renderVersionDetails);

    property('template-save').addEventListener('click', async () => {
        if (editor.dataset.manage !== 'true') {
            showMessage('Se requiere una sesión y el permiso de edición de plantillas.', true);
            return;
        }
        if (!isDirty()) {
            showMessage('No hay cambios nuevos que guardar.');
            return;
        }
        if (Number(config.width_mm) !== 54 || Number(config.height_mm) !== 86) {
            showMessage('El formato físico de esta plantilla debe ser 54 × 86 mm.', true);
            return;
        }
        if (!window.confirm('¿Guardar una nueva versión borrador? La versión aprobada no se reemplazará.')) {
            return;
        }

        try {
            const payload = await requestJson(editor.dataset.saveUrl, {
                method: 'POST',
                body: JSON.stringify({
                    config,
                    template_version: Number(editor.dataset.templateVersion || 1),
                    change_summary: property('template-summary').value || null,
                }),
            });
            savedSnapshot = JSON.stringify(config);
            editor.dataset.isSaved = 'true';
            editor.dataset.currentVersion = String(payload.version);
            property('template-summary').value = '';
            refreshStatus();
            showMessage(`${payload.message} (v${payload.version})`);
            await loadVersions();
            versionSelect.value = currentVersions.find((version) => version.version === payload.version)?.id ?? '';
            renderVersionDetails();
        } catch (error) {
            showMessage(error.message, true);
        }
    });

    property('template-cancel').addEventListener('click', () => {
        if (isDirty() && !window.confirm('¿Descartar todos los cambios que aún no se guardaron?')) {
            return;
        }
        replaceConfig(savedSnapshot);
        history = [savedSnapshot];
        historyIndex = 0;
        selectedId = null;
        property('template-name').value = config.name;
        faceSelect.value = 'front';
        renderPreview();
        refreshHistoryButtons();
        showMessage('Se cargó el último borrador guardado.');
    });

    property('template-reset').addEventListener('click', () => {
        if (!window.confirm('¿Cargar la plantilla predeterminada? Los borradores guardados no se borrarán.')) {
            return;
        }
        replaceConfig(JSON.stringify(defaults));
        selectedId = null;
        faceSelect.value = 'front';
        property('template-name').value = config.name;
        pushHistory();
        renderPreview();
        showMessage('Se cargó la plantilla predeterminada en el editor; guárdela si desea conservarla.');
    });

    property('template-restore').addEventListener('click', async () => {
        if (editor.dataset.manage !== 'true' || !versionSelect.value) {
            showMessage('Seleccione una versión guardada y compruebe su permiso de edición.', true);
            return;
        }
        if (isDirty() && !window.confirm('Hay cambios sin guardar. ¿Descartarlos y restaurar la versión seleccionada?')) {
            return;
        }
        if (!window.confirm('¿Restaurar esta versión como un nuevo borrador? La plantilla publicada no cambiará.')) {
            return;
        }
        try {
            const payload = await requestJson(`${editor.dataset.restoreUrl}/${versionSelect.value}`, { method: 'POST', body: '{}' });
            replaceConfig(JSON.stringify(payload.config));
            savedSnapshot = JSON.stringify(config);
            editor.dataset.isSaved = 'true';
            editor.dataset.currentVersion = String(payload.version);
            history = [savedSnapshot];
            historyIndex = 0;
            faceSelect.value = 'front';
            selectedId = null;
            property('template-name').value = config.name;
            renderPreview();
            refreshHistoryButtons();
            showMessage(`${payload.message} (v${payload.version})`);
            await loadVersions();
        } catch (error) {
            showMessage(error.message, true);
        }
    });

    property('template-approve').addEventListener('click', async () => {
        if (editor.dataset.approve !== 'true' || !versionSelect.value) {
            showMessage('Seleccione una versión guardada y compruebe su permiso de aprobación.', true);
            return;
        }
        if (!window.confirm('¿Aprobar y publicar esta versión para la vista de impresión? La última versión publicada será reemplazada.')) {
            return;
        }
        try {
            const payload = await requestJson(`${editor.dataset.approveUrl}/${versionSelect.value}`, { method: 'POST', body: '{}' });
            editor.dataset.publishedVersion = String(payload.version);
            editor.dataset.templateStatus = 'published';
            refreshStatus();
            showMessage(`${payload.message} (v${payload.version})`);
            await loadVersions();
        } catch (error) {
            showMessage(error.message, true);
        }
    });

    resourceInput.addEventListener('change', async () => {
        const file = resourceInput.files[0];
        const element = selectedElement();
        if (!file || !element || element.kind !== 'image') {
            return;
        }

        const form = new FormData();
        form.append('face', faceSelect.value);
        form.append('element_id', element.id);
        form.append('resource', file);

        try {
            const payload = await requestJson(editor.dataset.resourceUploadUrl, { method: 'POST', body: form });
            element.asset_path = payload.asset_path;
            element.image_aspect = payload.image_aspect;
            pushHistory();
            renderPreview();
            showMessage(`${payload.message} Guarde un borrador para versionar el cambio.`);
        } catch (error) {
            showMessage(error.message, true);
        } finally {
            resourceInput.value = '';
        }
    });

    const stageObserver = new ResizeObserver(() => {
        if (fitZoom) {
            updateZoom();
        }
    });
    stageObserver.observe(document.querySelector('.template-stage'));

    document.querySelectorAll('.template-version-switcher-link').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (isDirty() && !window.confirm('Hay cambios sin guardar en esta versión. ¿Deseas descartarlos y cambiar de versión?')) {
                event.preventDefault();
            }
        });
    });

    canvas.classList.toggle('credential-canvas-grid', property('template-grid-toggle').getAttribute('aria-pressed') === 'true');
    canvas.classList.toggle('credential-canvas-guides', property('template-guides-toggle').getAttribute('aria-pressed') === 'true');
    renderPreview();
    refreshHistoryButtons();
    loadVersions();
} else {
    const fitPrintText = () => {
        document.querySelectorAll('.credential-print-element').forEach((element) => {
            const text = element.querySelector('span');
            const rotation = Number(element.dataset.rotation);
            const width = Number(element.dataset.width);
            const height = Number(element.dataset.height);
            const isRotated = rotation !== 0;
            text.style.width = isRotated ? `${(height / width) * 100}%` : '100%';
            text.style.height = isRotated ? `${(width / height) * 100}%` : '100%';
            text.style.transform = `translate(-50%, -50%) rotate(${rotation}deg)`;
            fitCredentialText(element, element.dataset.fontSize);
        });
    };

    window.addEventListener('load', fitPrintText);
    window.addEventListener('beforeprint', fitPrintText);
}
