@extends('layouts.app')

@section('title', 'Mi perfil')
@section('heading', 'Mi perfil')
@section('subheading', 'Información de cuenta, preferencias y seguridad')

@section('content')
    <div role="status" class="mb-5 flex flex-wrap items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-800">
        <span>Estado de autenticación:</span>
        @if ($user)
            <x-status-badge label="Autenticado" status="completado" />
            <span class="ml-1 text-tx-soft">ID de cuenta: {{ $user->getAuthIdentifier() }}</span>
        @else
            <x-status-badge label="Sin autenticar" status="neutral" />
            <span class="ml-1 text-tx-soft">ID de cuenta: no disponible</span>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
            <x-card title="Información de cuenta" description="Resumen de la cuenta autenticada.">
                <div class="mb-5 flex items-center gap-4 rounded-lg bg-slate-50 p-4">
                    <div class="flex h-16 w-16 items-center justify-center rounded-full bg-inst-100 text-lg font-semibold text-inst-800">ID</div>
                    <div>
                        <p class="text-sm font-semibold text-inst-900">{{ $user ? 'Cuenta autenticada' : 'Sin usuario autenticado' }}</p>
                        <p class="mt-1 text-xs text-tx-soft">{{ $user ? 'ID: '.$user->getAuthIdentifier() : 'No hay una cuenta activa.' }}</p>
                    </div>
                    <div class="ml-auto">
                        <x-button variant="secondary" size="sm" :disabled="true" title="La gestión de fotografía requiere sesión y almacenamiento autorizado.">
                            Cambiar fotografía · no disponible
                        </x-button>
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @foreach (['Nombre completo', 'Correo institucional', 'Área', 'Rol', 'Usuario', 'Estado de cuenta'] as $field)
                        <label class="text-xs font-medium text-tx-soft">{{ $field }}
                            <input type="text" disabled value="No disponible" class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm text-tx-soft ring-1 ring-inset ring-slate-200">
                        </label>
                    @endforeach
                </div>
                <div class="mt-4 flex justify-end">
                    <x-button variant="primary" :disabled="true" title="La actualización de perfil requiere un endpoint autenticado.">
                        Guardar perfil · no disponible
                    </x-button>
                </div>
            </x-card>

            <x-card title="Preferencias">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <label class="text-xs font-medium text-tx-soft">Idioma<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Español</option></select></label>
                    <label class="text-xs font-medium text-tx-soft">Notificaciones<select disabled class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"><option>Preferencias no disponibles</option></select></label>
                </div>
                <p class="mt-3 text-xs text-tx-soft">Las preferencias visuales y de notificación no se guardan para esta vista sin sesión activa.</p>
            </x-card>
        </div>

        <div class="space-y-5">
            <x-card title="Seguridad" description="No existe un flujo seguro de cambio de contraseña en esta etapa.">
                <div class="space-y-4">
                    @foreach (['Contraseña actual', 'Nueva contraseña', 'Confirmar nueva contraseña'] as $field)
                        <label class="block text-xs font-medium text-tx-soft">{{ $field }}<input type="password" disabled autocomplete="off" class="mt-1 w-full rounded-md border-0 bg-slate-50 px-3 py-2 text-sm ring-1 ring-inset ring-slate-200"></label>
                    @endforeach
                    <x-button variant="primary" :disabled="true" title="No se procesa ni almacena ninguna contraseña desde esta pantalla." class="w-full">
                        Cambiar contraseña · no disponible
                    </x-button>
                </div>
            </x-card>
            <x-card title="Sesiones activas" description="La administración de sesiones activas no está disponible en esta pantalla.">
                <x-empty-table :columns="['Dispositivo', 'Última actividad', 'Acción']" message="Sin sesiones de usuario disponibles."/>
            </x-card>
        </div>
    </div>
@endsection
