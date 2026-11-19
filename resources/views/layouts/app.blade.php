<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Sistema Estatal para la Gestión y Control de Credenciales de Portación de Armas de Fuego — LOC No. 139">
    <title>@yield('title', 'Panel principal') · Sistema Estatal LOC 139</title>
    @stack('styles')
    {{-- Alpine se carga una sola vez desde el bundle (resources/js/app.js). --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="min-h-full bg-fondo font-sans text-tx antialiased">
    <a href="#view" class="sr-only z-50 rounded-md bg-white px-4 py-2 text-sm font-semibold text-inst-900 shadow-pop focus:not-sr-only focus:fixed focus:left-4 focus:top-4">
        Saltar al contenido principal
    </a>

    <div id="sidebar-overlay" class="fixed inset-0 z-30 hidden bg-inst-950/50 lg:hidden" aria-hidden="true"></div>

    @php
        $user = auth()->user();
        $grupos = [
            'Operación' => [
                ['label' => 'Panel principal', 'icon' => 'dashboard', 'route' => 'dashboard', 'active' => request()->routeIs('dashboard')],
                ['label' => 'Gestión de información', 'icon' => 'database', 'route' => 'gestion', 'active' => request()->routeIs('gestion*'), 'can' => 'access-administration'],
                ['label' => 'Credencialización', 'icon' => 'idcard', 'route' => 'credencializacion', 'active' => request()->routeIs('credencializacion*'), 'can' => 'access-credencializacion'],
                ['label' => 'Impresión y biometría', 'icon' => 'printer', 'route' => 'impresion', 'active' => request()->routeIs('impresion*'), 'can' => 'access-print-queue'],
                ['label' => 'Entrega y seguimiento', 'icon' => 'truck', 'route' => 'entregas', 'active' => request()->routeIs('entregas*'), 'can' => 'access-entregas'],
            ],
            'Consulta' => [
                ['label' => 'Consulta general', 'icon' => 'search', 'route' => 'consultas', 'active' => request()->routeIs('consultas*'), 'can' => 'access-consultas'],
                ['label' => 'Reportes', 'icon' => 'chart', 'route' => 'reportes', 'active' => request()->routeIs('reportes*')],
                ['label' => 'Avisos institucionales', 'icon' => 'bell', 'route' => 'avisos', 'active' => request()->routeIs('avisos*')],
            ],
            'Sistema' => [
                ['label' => 'Administración', 'icon' => 'settings', 'route' => 'administracion', 'active' => request()->routeIs('administracion*'), 'can' => 'access-administration'],
            ],
        ];
        // Solo se ocultan accesos que la ruta ya rechazaría: los permisos no cambian.
        foreach ($grupos as $grupo => $items) {
            $grupos[$grupo] = array_values(array_filter($items, fn ($item) => ! isset($item['can']) || $user?->can($item['can'])));
        }
        $grupos = array_filter($grupos);
        $rolEtiqueta = $user ? str((string) $user->getAttribute('role'))->replace('_', ' ')->title() : null;
        $esDemo = (bool) $user?->getAttribute('local_demo_access');
        $entorno = app()->environment();
    @endphp

    <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col bg-inst-900 transition-transform duration-200 lg:translate-x-0" aria-label="Menú del sistema">
        <a href="{{ route('dashboard') }}" class="inst-pattern flex items-center gap-3 border-b border-white/10 px-5 py-5 focus-visible:ring-offset-inst-900">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white text-inst-900 shadow-sm">
                <x-icon name="shield" class="h-5 w-5"/>
            </span>
            <span class="min-w-0">
                <span class="block truncate text-sm font-semibold tracking-tight text-white">Sistema Estatal</span>
                <span class="block truncate text-xs text-inst-200">Credenciales · LOC N.º 139</span>
            </span>
        </a>

        <nav class="flex-1 overflow-y-auto px-3 py-5" aria-label="Navegación principal">
            @foreach ($grupos as $grupo => $items)
                <p class="mb-2 mt-5 px-3 text-2xs font-semibold uppercase tracking-[0.14em] text-inst-300 first:mt-0">{{ $grupo }}</p>
                <ul class="space-y-0.5">
                    @foreach ($items as $item)
                        <li>
                            <a href="{{ route($item['route']) }}" @if ($item['active']) aria-current="page" @endif
                               class="group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors focus-visible:ring-offset-inst-900 {{ $item['active'] ? 'bg-white/10 text-white' : 'text-inst-100 hover:bg-white/5 hover:text-white' }}">
                                @if ($item['active'])
                                    <span class="absolute inset-y-1.5 left-0 w-1 rounded-r-full bg-white" aria-hidden="true"></span>
                                @endif
                                <x-icon :name="$item['icon']" class="h-[1.125rem] w-[1.125rem] shrink-0 {{ $item['active'] ? 'text-white' : 'text-inst-300 group-hover:text-white' }}"/>
                                <span class="truncate">{{ $item['label'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endforeach
        </nav>

        @if ($user)
            <div class="border-t border-white/10 p-3">
                <div class="flex items-center gap-3 rounded-lg px-2 py-2">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white/10 text-sm font-semibold text-white ring-1 ring-white/15" aria-hidden="true">
                        {{ mb_strtoupper(mb_substr((string) $user->name, 0, 1)) }}
                    </span>
                    <a href="{{ route('perfil') }}" class="min-w-0 flex-1 rounded focus-visible:ring-offset-inst-900">
                        <span class="block truncate text-sm font-medium text-white">{{ $user->name }}</span>
                        <span class="block truncate text-2xs text-inst-200">{{ $esDemo ? 'Acceso demo local' : ($rolEtiqueta ?: 'Mi perfil') }}</span>
                    </a>
                    @unless ($esDemo)
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" title="Cerrar sesión" aria-label="Cerrar sesión"
                                    class="rounded-md p-2 text-inst-200 transition-colors hover:bg-white/10 hover:text-white focus-visible:ring-offset-inst-900">
                                <x-icon name="logout" class="h-4 w-4"/>
                            </button>
                        </form>
                    @endunless
                </div>
            </div>
        @endif
    </aside>

    <div class="flex min-h-screen flex-col lg:pl-72">
        <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-linea bg-white/95 px-4 backdrop-blur sm:px-6 lg:px-8">
            <button type="button" id="sidebar-toggle" class="btn-ghost btn-icon -ml-2 rounded-md lg:hidden"
                    aria-label="Abrir menú" aria-controls="sidebar" aria-expanded="false">
                <x-icon name="menu"/>
            </button>
            <nav aria-label="Ubicación" class="min-w-0">
                <ol class="flex items-center gap-1.5 text-sm">
                    <li class="hidden sm:block"><a href="{{ route('dashboard') }}" class="rounded text-tx-soft hover:text-inst-900">LOC 139</a></li>
                    <li class="hidden text-slate-300 sm:block" aria-hidden="true"><x-icon name="chevronRight" class="h-3.5 w-3.5"/></li>
                    <li class="truncate font-medium text-inst-900" aria-current="page" data-page-breadcrumb>@yield('heading', 'Panel principal')</li>
                </ol>
            </nav>
            <div class="ml-auto flex items-center gap-2">
                @if ($entorno !== 'production')
                    <span class="hidden items-center gap-1.5 rounded-full border border-inst-200 bg-inst-50 px-2.5 py-1 text-2xs font-semibold uppercase tracking-wider text-inst-800 sm:inline-flex"
                          title="Entorno de ejecución: {{ $entorno }}">
                        <span class="h-1.5 w-1.5 rounded-full bg-inst-600" aria-hidden="true"></span>
                        Entorno {{ $entorno === 'local' ? 'local' : $entorno }}
                    </span>
                @endif
                @if ($esDemo)
                    <span class="rounded-full border border-amber-300 bg-amber-50 px-2.5 py-1 text-2xs font-semibold uppercase tracking-wider text-amber-900">Demo local</span>
                @endif
                <a href="{{ route('avisos') }}" title="Avisos institucionales" aria-label="Avisos institucionales" class="btn-ghost btn-icon rounded-md">
                    <x-icon name="bell" class="h-[1.125rem] w-[1.125rem]"/>
                </a>
            </div>
        </header>

        <main id="view" tabindex="-1" class="mx-auto w-full max-w-screen-2xl flex-1 px-4 py-6 focus:outline-none sm:px-6 lg:px-8 lg:py-8">
            @if ($esDemo)
                <div role="status" class="alert alert-warning mb-5">
                    <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0"/>
                    <span>Modo demo local de solo lectura. No se guardarán cambios, cargas ni confirmaciones.</span>
                </div>
            @endif

            <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
                <div class="min-w-0">
                    <h1 class="text-2xl font-semibold tracking-tight text-inst-900" data-page-heading>@yield('heading', 'Panel principal')</h1>
                    <p class="mt-1 text-sm text-tx-soft" data-page-subheading>@yield('subheading', 'Sistema Estatal LOC No. 139')</p>
                </div>
                @hasSection('page-actions')
                    <div class="flex flex-wrap items-center gap-2">@yield('page-actions')</div>
                @endif
            </div>

            <div data-page-avisos>@include('layouts.partials.avisos')</div>
            @yield('content')
        </main>

        <footer class="border-t border-linea px-4 py-4 text-xs text-tx-soft sm:px-6 lg:px-8">
            <div class="mx-auto flex max-w-screen-2xl flex-wrap items-center justify-between gap-2">
                <span>Sistema Estatal para la Gestión y Control de Credenciales · LOC No. 139</span>
                <span>Uso institucional restringido</span>
            </div>
        </footer>
    </div>

    @stack('scripts')
</body>
</html>
