@extends('layouts.app')

{{--
    Contenedor de Entrega y Seguimiento con navegación dinámica (resources/js/entrega-navegacion.js): enlaces y
    formularios del módulo se resuelven por fetch y solo se sustituye la sección "entrega". Sin JavaScript, todo
    funciona como navegación tradicional; cada URL sigue entregando la página completa.
--}}
@section('content')
    <div x-data="crearNavegacionEntrega()" @click="alClic($event)" @submit="alEnviar($event)" data-entrega-navegacion class="relative">
        <div x-show="cargando" x-cloak class="pointer-events-none absolute inset-x-0 -top-3 h-0.5 overflow-hidden rounded-full bg-inst-100" aria-hidden="true">
            <div class="h-full w-1/3 animate-pulse rounded-full bg-inst-700"></div>
        </div>
        <p x-show="cargando" x-cloak class="sr-only" role="status" aria-live="polite">Cargando…</p>

        <div x-show="error" x-cloak role="alert" class="alert alert-danger mb-5" data-navegacion-error>
            <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0"/>
            <div>
                <p class="font-semibold" x-text="error"></p>
                <ul x-show="errores.length" class="mt-1 list-inside list-disc space-y-0.5 text-xs">
                    <template x-for="mensaje in errores"><li x-text="mensaje"></li></template>
                </ul>
            </div>
        </div>

        <div x-ref="contenido" tabindex="-1" :aria-busy="cargando" class="transition-opacity focus:outline-none"
             :class="cargando ? 'pointer-events-none opacity-60' : ''">
            @yield('entrega')
        </div>
    </div>
@endsection
