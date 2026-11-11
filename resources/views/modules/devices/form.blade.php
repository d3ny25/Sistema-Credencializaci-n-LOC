@extends('layouts.app')

@section('title', $device ? 'Editar dispositivo' : 'Registrar dispositivo')
@section('heading', 'Administración')
@section('subheading', $device ? 'Editar configuración registrada' : 'Registrar equipo del inventario CPAF')

@section('content')
    @include('modules.devices._admin_tabs', ['active' => 'dispositivos'])

    <div class="mx-auto max-w-3xl rounded-lg border border-slate-200 bg-white p-5 sm:p-7">
        <form method="POST" action="{{ $device ? route('administracion.dispositivos.actualizar', $device) : route('administracion.dispositivos.guardar') }}" class="space-y-5">
            @csrf
            @if ($device)
                @method('PUT')
                <input type="hidden" name="type" value="{{ $device->type }}">
            @endif

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @if ($device)
                    <div>
                        <span class="block text-xs font-medium text-tx-soft">Tipo de dispositivo</span>
                        <p class="mt-1 rounded-md bg-slate-50 px-3 py-2 text-sm text-tx">{{ $types[$device->type]['label'] }}</p>
                    </div>
                @else
                    <label class="text-xs font-medium text-tx-soft">
                        Tipo de dispositivo
                        <select id="device-type" name="type" required class="mt-1 block w-full rounded-md border-slate-300 text-sm text-tx shadow-sm focus:border-inst-700 focus:ring-inst-700">
                            @foreach ($types as $key => $type)
                                <option value="{{ $key }}" @selected(old('type', array_key_first($types)) === $key)>{{ $type['label'] }} — {{ $type['name'] }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif

                <div>
                    <span class="block text-xs font-medium text-tx-soft">Fabricante / modelo</span>
                    <p id="device-model" class="mt-1 rounded-md bg-slate-50 px-3 py-2 text-sm text-tx">
                        {{ $device ? $device->manufacturer.' · '.$device->model : '' }}
                    </p>
                </div>

                <label class="text-xs font-medium text-tx-soft sm:col-span-2">
                    Nombre identificativo
                    <input type="text" name="name" value="{{ old('name', $device?->name) }}" maxlength="120" required
                           class="mt-1 block w-full rounded-md border-slate-300 text-sm text-tx shadow-sm focus:border-inst-700 focus:ring-inst-700">
                    @error('name') <span class="mt-1 block text-xs text-red-700">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs font-medium text-tx-soft">
                    Número de serie / identificador interno
                    <input type="text" name="identifier" value="{{ old('identifier', $device?->identifier) }}" maxlength="120"
                           class="mt-1 block w-full rounded-md border-slate-300 text-sm text-tx shadow-sm focus:border-inst-700 focus:ring-inst-700">
                    @error('identifier') <span class="mt-1 block text-xs text-red-700">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs font-medium text-tx-soft">
                    Ubicación o estación de trabajo
                    <input type="text" name="location" value="{{ old('location', $device?->location) }}" maxlength="120"
                           class="mt-1 block w-full rounded-md border-slate-300 text-sm text-tx shadow-sm focus:border-inst-700 focus:ring-inst-700">
                    @error('location') <span class="mt-1 block text-xs text-red-700">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs font-medium text-tx-soft sm:col-span-2">
                    Método de conexión previsto
                    <select id="connection-method" name="connection_method" required class="mt-1 block w-full rounded-md border-slate-300 text-sm text-tx shadow-sm focus:border-inst-700 focus:ring-inst-700">
                        @foreach ($types as $key => $type)
                            @foreach ($type['connection_methods'] as $method => $label)
                                <option value="{{ $method }}" data-device-type="{{ $key }}" @selected(old('connection_method', $device?->connection_method) === $method)>{{ $label }}</option>
                            @endforeach
                        @endforeach
                    </select>
                    @error('connection_method') <span class="mt-1 block text-xs text-red-700">{{ $message }}</span> @enderror
                </label>
            </div>

            <div>
                <h2 class="text-sm font-semibold text-inst-900">Configuración específica</h2>
                <p class="mt-1 text-xs leading-5 text-tx-soft">Solo se almacenan opciones permitidas por tipo; no registres claves, contraseñas, rutas con credenciales ni secretos.</p>
                @foreach ($types as $key => $type)
                    @if ($type['configuration'])
                        <div class="device-config mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2" data-device-type="{{ $key }}">
                            @foreach ($type['configuration'] as $fieldKey => $field)
                                <label class="text-xs font-medium text-tx-soft">
                                    {{ $field['label'] }}
                                    @if ($field['input'] === 'select')
                                        <select name="configuration[{{ $fieldKey }}]" class="mt-1 block w-full rounded-md border-slate-300 text-sm text-tx shadow-sm focus:border-inst-700 focus:ring-inst-700">
                                            <option value="">Selecciona…</option>
                                            @foreach ($field['options'] as $value => $label)
                                                <option value="{{ $value }}" @selected(old('configuration.'.$fieldKey, $device?->configuration[$fieldKey] ?? '') === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <input type="{{ $field['input'] }}" name="configuration[{{ $fieldKey }}]" value="{{ old('configuration.'.$fieldKey, $device?->configuration[$fieldKey] ?? '') }}" step="{{ $field['step'] }}"
                                               class="mt-1 block w-full rounded-md border-slate-300 text-sm text-tx shadow-sm focus:border-inst-700 focus:ring-inst-700">
                                    @endif
                                    @error('configuration.'.$fieldKey) <span class="mt-1 block text-xs text-red-700">{{ $message }}</span> @enderror
                                </label>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            </div>

            @if ($device)
                <p class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-950">
                    El estado administrativo solo puede cambiarlo un administrador en la ficha del dispositivo. Guardar esta configuración no verifica la conexión.
                </p>
            @else
                <p class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-950">
                    El registro inicial queda pendiente de verificación. Guardarlo no habilita ni conecta el equipo.
                </p>
            @endif

            <div class="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4">
                <a href="{{ $device ? route('administracion.dispositivos.detalle', $device) : route('administracion.dispositivos') }}" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-tx hover:bg-slate-50">Cancelar</a>
                <button type="submit" class="rounded-md bg-inst-700 px-4 py-2 text-sm font-semibold text-white hover:bg-inst-900">{{ $device ? 'Guardar cambios' : 'Registrar dispositivo' }}</button>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const catalog = @json(collect($types)->map(fn ($type) => ['manufacturer' => $type['manufacturer'], 'model' => $type['model']])->all());
            const typeSelector = document.getElementById('device-type');
            const connectionSelector = document.getElementById('connection-method');
            const modelLabel = document.getElementById('device-model');

            if (!typeSelector) {
                return;
            }

            const updateTypeFields = () => {
                const type = typeSelector.value;
                modelLabel.textContent = `${catalog[type].manufacturer} · ${catalog[type].model}`;
                connectionSelector.querySelectorAll('option').forEach((option) => {
                    option.hidden = option.dataset.deviceType !== type;
                });
                const validConnection = [...connectionSelector.options].find((option) => !option.hidden);
                if (validConnection && connectionSelector.selectedOptions[0]?.hidden) {
                    connectionSelector.value = validConnection.value;
                }
                document.querySelectorAll('.device-config').forEach((fields) => {
                    const isVisible = fields.dataset.deviceType === type;
                    fields.hidden = !isVisible;
                    fields.querySelectorAll('input, select, textarea').forEach((field) => {
                        field.disabled = !isVisible;
                    });
                });
            };

            typeSelector.addEventListener('change', updateTypeFields);
            updateTypeFields();
        })();
    </script>
@endpush
