@props([
    'name' => 'archivo',
    'id' => null,
    'label' => 'Selecciona o arrastra un archivo',
    'help' => null,
    'accept' => '.xls,application/vnd.ms-excel',
    'allowedExtensions' => ['xls'],
    'maxKilobytes' => 51200, // 50 MB
    'buttonText' => 'Seleccionar archivo',
    'required' => false,
    'disabled' => false,
    'showClear' => true,
    'serverError' => null,
])

@php
    $inputId = $id ?? $name;
    
    // Normalize allowed extensions to a lowercase array without dots
    if (is_string($allowedExtensions)) {
        $normalizedExtensions = array_map(function($ext) {
            return strtolower(ltrim(trim($ext), '.'));
        }, explode(',', $allowedExtensions));
    } elseif (is_array($allowedExtensions)) {
        $normalizedExtensions = array_map(function($ext) {
            return strtolower(ltrim(trim($ext), '.'));
        }, $allowedExtensions);
    } else {
        $normalizedExtensions = ['xls'];
    }
    $normalizedExtensions = array_values(array_filter($normalizedExtensions));

    $extensionsHelp = !empty($normalizedExtensions) 
        ? implode(', ', array_map(fn($e) => '.' . $e, $normalizedExtensions))
        : 'Cualquier extensión admitida';

    $maxMbFormatted = number_format($maxKilobytes / 1024, 1);
    $defaultHelp = "Formatos admitidos: {$extensionsHelp} · Máx. {$maxMbFormatted} MB";
    $finalHelp = $help ?? $defaultHelp;
    $maxBytes = $maxKilobytes * 1024;

    $hasServerError = false;
    $serverErrorMessage = $serverError;

    if (isset($errors) && $errors instanceof \Illuminate\Support\ViewErrorBag && $errors->has($name)) {
        $hasServerError = true;
        $serverErrorMessage = $errors->first($name);
    } elseif ($serverError) {
        $hasServerError = true;
    }
@endphp

<div {{ $attributes->class(['w-full space-y-2']) }}
     x-data="{
        isDragging: false,
        fileName: '',
        fileSizeFormatted: '',
        fileSizeBytes: 0,
        isValid: null,
        errorMessage: '',
        disabled: {{ $disabled ? 'true' : 'false' }},
        maxBytes: {{ (int)$maxBytes }},
        allowedExtensions: {{ json_encode($normalizedExtensions) }},

        formatBytes(bytes) {
            if (!bytes || bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },

        handleFiles(files) {
            if (this.disabled) return;
            const file = files && files[0];
            const input = this.$refs.fileInput;

            if (!file) {
                this.clear();
                return;
            }

            // Sync with input if coming from drag and drop
            if (input && input.files !== files) {
                try {
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    input.files = dt.files;
                } catch (e) {
                    // Fallback in environments where DataTransfer is not constructible
                }
            }

            this.fileName = file.name;
            this.fileSizeBytes = file.size;
            this.fileSizeFormatted = this.formatBytes(file.size);
            this.errorMessage = '';

            // 1. Extension validation
            const extParts = file.name.split('.');
            const ext = extParts.length > 1 ? extParts.pop().toLowerCase() : '';
            if (this.allowedExtensions.length > 0 && !this.allowedExtensions.includes(ext)) {
                const allowedFormatted = this.allowedExtensions.map(e => '.' + e).join(', ');
                this.errorMessage = `Extensión no válida (.${ext}). Solo se admiten archivos: ${allowedFormatted}.`;
                this.isValid = false;
                this.$dispatch('file-validation-failed', { name: '{{ $name }}', file: file, error: this.errorMessage });
                return;
            }

            // 2. Size validation
            if (file.size > this.maxBytes) {
                this.errorMessage = `El archivo (${this.fileSizeFormatted}) supera el límite institucional de ${this.formatBytes(this.maxBytes)}.`;
                this.isValid = false;
                this.$dispatch('file-validation-failed', { name: '{{ $name }}', file: file, error: this.errorMessage });
                return;
            }

            this.isValid = true;
            this.$dispatch('file-selected', { name: '{{ $name }}', file: file, valid: true });
        },

        onDragOver(e) {
            if (this.disabled) return;
            this.isDragging = true;
        },

        onDragLeave(e) {
            this.isDragging = false;
        },

        onDrop(e) {
            this.isDragging = false;
            if (this.disabled) return;
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                this.handleFiles(e.dataTransfer.files);
            }
        },

        clear() {
            this.fileName = '';
            this.fileSizeFormatted = '';
            this.fileSizeBytes = 0;
            this.isValid = null;
            this.errorMessage = '';
            if (this.$refs.fileInput) {
                this.$refs.fileInput.value = '';
            }
            this.$dispatch('file-cleared', { name: '{{ $name }}' });
        }
     }">

    <!-- Dropzone Area -->
    <div class="relative rounded-lg border-2 border-dashed transition-all duration-150"
         :class="{
            'border-slate-300 bg-slate-50/70 hover:border-inst-700 hover:bg-inst-50/40': !isDragging && isValid === null && !disabled,
            'border-inst-700 bg-inst-50 ring-2 ring-inst-700/20': isDragging && !disabled,
            'border-emerald-400 bg-emerald-50/30': isValid === true && !isDragging,
            'border-red-400 bg-red-50/30': isValid === false && !isDragging,
            'opacity-60 cursor-not-allowed bg-slate-100 border-slate-200': disabled
         }"
         @dragenter.prevent="onDragOver($event)"
         @dragover.prevent="onDragOver($event)"
         @dragleave.prevent="onDragLeave($event)"
         @drop.prevent="onDrop($event)"
         role="region"
         aria-label="{{ $label }}">

        <label for="{{ $inputId }}"
               class="flex flex-col items-center justify-center p-6 text-center cursor-pointer select-none focus:outline-none"
               :class="{ 'cursor-not-allowed': disabled }"
               tabindex="0"
               @keydown.enter.prevent="$refs.fileInput.click()"
               @keydown.space.prevent="$refs.fileInput.click()">

            <!-- State 1: Default / Empty -->
            <template x-if="isValid === null">
                <div class="flex flex-col items-center pointer-events-none">
                    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-inst-50 text-inst-700 mb-3 transition-transform group-hover:scale-105"
                          :class="{ 'bg-inst-100 text-inst-900': isDragging }">
                        <x-icon name="upload" class="h-6 w-6" />
                    </span>
                    <span class="text-sm font-semibold text-tx">
                        {{ $label }}
                    </span>
                    <span class="mt-1 text-xs text-tx-soft">
                        {{ $finalHelp }}
                    </span>
                    <span class="mt-3 inline-flex items-center rounded-md bg-inst-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-inst-900"
                          :class="{ 'opacity-50 pointer-events-none': disabled }">
                        {{ $buttonText }}
                    </span>
                </div>
            </template>

            <!-- State 2: Valid File Selected -->
            <template x-if="isValid === true">
                <div class="flex flex-col items-center text-center pointer-events-none">
                    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 mb-2">
                        <x-icon name="check" class="h-6 w-6" />
                    </span>
                    <span class="text-sm font-semibold text-slate-900 break-all" x-text="fileName"></span>
                    <span class="mt-0.5 text-xs text-emerald-800 font-medium">
                        Archivo seleccionado para enviar · <span x-text="fileSizeFormatted"></span>
                    </span>
                    <p class="mt-1 text-[11px] text-tx-soft">
                        Tamaño preliminar válido; el servidor verificará la estructura al enviar.
                    </p>
                </div>
            </template>

            <!-- State 3: Invalid File -->
            <template x-if="isValid === false">
                <div class="flex flex-col items-center text-center pointer-events-none">
                    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-red-700 mb-2">
                        <x-icon name="alert" class="h-6 w-6" />
                    </span>
                    <span class="text-sm font-semibold text-slate-900 break-all" x-text="fileName"></span>
                    <span class="mt-0.5 text-xs text-red-700 font-medium" x-text="errorMessage"></span>
                    <p class="mt-1 text-[11px] text-tx-soft">
                        Selecciona un archivo que cumpla con los requisitos antes de enviar.
                    </p>
                </div>
            </template>

            <!-- Hidden Native File Input -->
            <input x-ref="fileInput"
                   id="{{ $inputId }}"
                   name="{{ $name }}"
                   type="file"
                   accept="{{ $accept }}"
                   @change="handleFiles($event.target.files)"
                   @if ($required) required @endif
                   @if ($disabled) disabled @endif
                   aria-describedby="{{ $inputId }}-status {{ $inputId }}-server-error"
                   class="sr-only">
        </label>

        <!-- Clear Selection Button (shown when a file is selected) -->
        @if ($showClear)
            <div x-show="fileName"
                 class="absolute top-3 right-3"
                 x-cloak>
                <button type="button"
                        @click.stop.prevent="clear()"
                        :disabled="disabled"
                        class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white/90 px-2 py-1 text-xs font-medium text-slate-600 shadow-sm backdrop-blur hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-inst-700 disabled:opacity-50"
                        title="Quitar archivo seleccionado"
                        aria-label="Quitar archivo seleccionado">
                    <x-icon name="x" class="h-3.5 w-3.5" />
                    <span>Quitar</span>
                </button>
            </div>
        @endif
    </div>

    <!-- Live Status Details for Screen Readers -->
    <div id="{{ $inputId }}-status"
         class="sr-only"
         aria-live="polite"
         x-text="isValid === true ? `Archivo ${fileName} listo para enviar.` : (isValid === false ? errorMessage : '')">
    </div>

    <!-- Server-side Error Display -->
    @if ($hasServerError && $serverErrorMessage)
        <p id="{{ $inputId }}-server-error" class="text-xs font-semibold text-estado-error" role="alert">
            {{ $serverErrorMessage }}
        </p>
    @endif
</div>
