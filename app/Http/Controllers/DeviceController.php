<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceEvent;
use App\Services\DeviceDiagnostics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function index(Request $request): View
    {
        $types = config('devices.types');
        $statuses = [
            'pending' => 'Pendiente de verificación',
            'enabled' => 'Habilitado',
            'disabled' => 'Deshabilitado',
            'out_of_service' => 'Fuera de servicio',
        ];

        $filters = $request->validate([
            'type' => ['nullable', Rule::in(array_keys($types))],
            'status' => ['nullable', Rule::in(array_keys($statuses))],
            'connection' => ['nullable', Rule::in(['not_tested', DeviceEvent::RESULT_UNAVAILABLE])],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $query = Device::query();

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['status'])) {
            $query->where('administrative_status', $filters['status']);
        }

        if (($filters['connection'] ?? null) === 'not_tested') {
            $query->whereNull('last_test_result');
        } elseif (($filters['connection'] ?? null) === DeviceEvent::RESULT_UNAVAILABLE) {
            $query->where('last_test_result', DeviceEvent::RESULT_UNAVAILABLE);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(static function ($query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('identifier', 'like', '%'.$search.'%')
                    ->orWhere('model', 'like', '%'.$search.'%');
            });
        }

        return view('modules.devices.index', [
            'devices' => $query->orderBy('name')->paginate(20)->withQueryString(),
            'filters' => $filters,
            'types' => $types,
            'statuses' => $statuses,
            'summary' => [
                'total' => Device::count(),
                'enabled' => Device::where('administrative_status', 'enabled')->count(),
                'pending' => Device::where('administrative_status', 'pending')->count(),
                'out_of_service' => Device::where('administrative_status', 'out_of_service')->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('modules.devices.form', [
            'device' => null,
            'types' => config('devices.types'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateDevice($request);
        $type = config('devices.types.'.$validated['type']);

        $device = DB::transaction(function () use ($request, $validated, $type): Device {
            $device = Device::query()->create([
                ...$this->deviceAttributes($validated, $type),
                'administrative_status' => 'pending',
                'connection_status' => 'unknown',
                'created_by' => $request->user()->getKey(),
                'updated_by' => $request->user()->getKey(),
            ]);

            $this->recordEvent($device, $request, 'registered', 'Dispositivo registrado con estado pendiente de verificación.');

            return $device;
        });

        return redirect()
            ->route('administracion.dispositivos.detalle', $device)
            ->with('status', 'El dispositivo se registró y quedó pendiente de verificación.');
    }

    public function show(Device $device): View
    {
        return view('modules.devices.show', [
            'device' => $device,
            'type' => config('devices.types.'.$device->type),
            'statuses' => [
                'pending' => 'Pendiente de verificación',
                'enabled' => 'Habilitado',
                'disabled' => 'Deshabilitado',
                'out_of_service' => 'Fuera de servicio',
            ],
            'events' => $device->events()->with('user')->limit(50)->get(),
        ]);
    }

    public function edit(Device $device): View
    {
        return view('modules.devices.form', [
            'device' => $device,
            'types' => config('devices.types'),
        ]);
    }

    public function update(Request $request, Device $device): RedirectResponse
    {
        $validated = $this->validateDevice($request, $device);
        $type = config('devices.types.'.$device->type);
        $changedFields = array_keys($this->deviceAttributes($validated, $type));

        DB::transaction(function () use ($request, $device, $validated, $type, $changedFields): void {
            $device->update([
                ...$this->deviceAttributes($validated, $type),
                'updated_by' => $request->user()->getKey(),
            ]);

            $this->recordEvent(
                $device,
                $request,
                'updated',
                'Se actualizó la configuración administrativa del dispositivo.',
                ['changed_fields' => $changedFields],
            );
        });

        return redirect()
            ->route('administracion.dispositivos.detalle', $device)
            ->with('status', 'Se guardaron los cambios del dispositivo.');
    }

    public function updateStatus(Request $request, Device $device): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['enabled', 'disabled', 'out_of_service'])],
        ]);

        $previousStatus = $device->administrative_status;

        DB::transaction(function () use ($request, $device, $validated, $previousStatus): void {
            $device->update([
                'administrative_status' => $validated['status'],
                'updated_by' => $request->user()->getKey(),
            ]);

            $this->recordEvent(
                $device,
                $request,
                'status_changed',
                'Se cambió el estado administrativo del dispositivo.',
                ['from' => $previousStatus, 'to' => $validated['status']],
            );
        });

        return redirect()
            ->route('administracion.dispositivos.detalle', $device)
            ->with('status', 'Se actualizó el estado administrativo. Esto no comprueba la conexión física.');
    }

    public function diagnose(Request $request, Device $device, DeviceDiagnostics $diagnostics): RedirectResponse
    {
        $result = $diagnostics->run($device);

        DB::transaction(function () use ($request, $device, $result): void {
            $device->update([
                'last_checked_at' => now(),
                'last_test_result' => $result['result'],
                'last_test_message' => $result['message'],
            ]);

            $this->recordEvent(
                $device,
                $request,
                'diagnostic',
                $result['message'],
                [],
                $result['operation'],
                $result['result'],
                $result['duration_ms'],
            );
        });

        return redirect()
            ->route('administracion.dispositivos.detalle', $device)
            ->with('status', 'Diagnóstico registrado: no disponible desde el servidor. No se ejecutó una prueba física.');
    }

    private function validateDevice(Request $request, ?Device $device = null): array
    {
        $type = $request->input('type');

        if (! is_string($type) || ! isset(config('devices.types')[$type])) {
            throw ValidationException::withMessages([
                'type' => 'Selecciona uno de los dispositivos identificados en el catálogo CPAF.',
            ]);
        }

        if ($device && $device->type !== $type) {
            throw ValidationException::withMessages([
                'type' => 'El tipo físico del dispositivo no se puede cambiar después de registrarlo.',
            ]);
        }

        $definition = config('devices.types.'.$type);
        $configurationKeys = array_keys($definition['configuration']);
        $configurationRules = $configurationKeys
            ? ['nullable', 'array:'.implode(',', $configurationKeys)]
            : ['nullable', 'array', 'size:0'];

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(array_keys(config('devices.types')))],
            'identifier' => [
                'nullable',
                'string',
                'max:120',
                Rule::unique('devices', 'identifier')->ignore($device?->getKey()),
            ],
            'location' => ['nullable', 'string', 'max:120'],
            'connection_method' => [
                'required',
                Rule::in(array_keys($definition['connection_methods'])),
            ],
            'configuration' => $configurationRules,
        ];

        foreach ($definition['configuration'] as $key => $field) {
            $rules['configuration.'.$key] = ['nullable', ...$field['rules']];
        }

        return $request->validate($rules);
    }

    private function deviceAttributes(array $validated, array $definition): array
    {
        $configuration = array_filter(
            $validated['configuration'] ?? [],
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        return [
            'name' => $validated['name'],
            'type' => $validated['type'],
            'manufacturer' => $definition['manufacturer'],
            'model' => $definition['model'],
            'identifier' => $validated['identifier'] ?? null,
            'location' => $validated['location'] ?? null,
            'connection_method' => $validated['connection_method'],
            'configuration' => $configuration,
        ];
    }

    private function recordEvent(
        Device $device,
        Request $request,
        string $eventType,
        string $message,
        array $metadata = [],
        ?string $operation = null,
        ?string $result = null,
        ?int $durationMs = null,
    ): void {
        $device->events()->create([
            'user_id' => $request->user()->getKey(),
            'event_type' => $eventType,
            'operation' => $operation,
            'result' => $result,
            'message' => $message,
            'metadata' => $metadata ?: null,
            'duration_ms' => $durationMs,
            'created_at' => now(),
        ]);
    }
}
