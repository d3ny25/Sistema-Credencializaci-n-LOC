@php
    $items = [
        ['key' => 'usuarios', 'label' => 'Usuarios', 'route' => 'administracion', 'parameters' => ['section' => 'usuarios']],
        ['key' => 'roles', 'label' => 'Roles y permisos', 'route' => 'administracion', 'parameters' => ['section' => 'roles']],
        ['key' => 'catalogos', 'label' => 'Catálogos', 'route' => 'administracion', 'parameters' => ['section' => 'catalogos']],
        ['key' => 'avisos', 'label' => 'Avisos', 'route' => 'administracion', 'parameters' => ['section' => 'avisos']],
        ['key' => 'bitacora', 'label' => 'Bitácora', 'route' => 'administracion', 'parameters' => ['section' => 'bitacora']],
        ['key' => 'diseno-credencial', 'label' => 'Diseño de credencial', 'route' => 'impresion.plantilla'],
    ];
    if (auth()->user()?->can('view-devices')) {
        $items[] = ['key' => 'dispositivos', 'label' => 'Gestión de dispositivos', 'route' => 'administracion.dispositivos'];
    }
@endphp

<x-module-tabs :active="$active" :items="$items"/>
