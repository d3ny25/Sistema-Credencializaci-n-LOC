<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Sistema Estatal para la Gestión y Control de Credenciales de Portación de Armas de Fuego — LOC No. 139">
    <title>@yield('title', 'Panel principal') · Sistema Estatal LOC 139</title>
    @stack('styles')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="min-h-full bg-fondo font-sans text-tx antialiased">
    <div id="sidebar-overlay" class="fixed inset-0 z-30 hidden bg-inst-900/40 lg:hidden" aria-hidden="true"></div>

    <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col bg-inst-900 transition-transform duration-200 lg:translate-x-0">
        <div class="inst-pattern flex items-center gap-3 border-b border-white/10 px-5 py-4">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-white/10 text-white ring-1 ring-white/15">
                <x-icon name="shield" class="h-6 w-6"/>
            </span>
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-white">Sistema Estatal</p>
                <p class="truncate text-xs text-inst-50/70">Credenciales · LOC N.º 139</p>
            </div>
        </div>

        <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4" aria-label="Navegación principal">
            @php
                $navigation = [
                    ['label' => 'Panel principal', 'icon' => 'dashboard', 'route' => 'dashboard', 'active' => request()->routeIs('dashboard')],
                    ['label' => 'Gestión de información', 'icon' => 'database', 'route' => 'gestion', 'active' => request()->routeIs('gestion*')],
                    ['label' => 'Credencialización', 'icon' => 'idcard', 'route' => 'credencializacion', 'active' => request()->routeIs('credencializacion*')],
                    ['label' => 'Impresión', 'icon' => 'printer', 'route' => 'impresion', 'active' => request()->routeIs('impresion*')],
                    ['label' => 'Entrega y seguimiento', 'icon' => 'truck', 'route' => 'entregas', 'active' => request()->routeIs('entregas*')],
                    ['label' => 'Consulta general', 'icon' => 'search', 'route' => 'consultas', 'active' => request()->routeIs('consultas*')],
                    ['label' => 'Reportes', 'icon' => 'chart', 'route' => 'reportes', 'active' => request()->routeIs('reportes*')],
                ];
                if (auth()->user()?->can('access-administration')) {
                    $navigation[] = ['label' => 'Administración', 'icon' => 'settings', 'route' => 'administracion', 'active' => request()->routeIs('administracion*')];
                }
            @endphp
            @foreach ($navigation as $item)
                <a href="{{ route($item['route']) }}" @if ($item['active']) aria-current="page" @endif
                   class="group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors {{ $item['active'] ? 'bg-white/10 text-white' : 'text-inst-50/80 hover:bg-white/5 hover:text-white' }}">
                    <x-icon :name="$item['icon']" class="h-5 w-5 text-inst-50/70 group-hover:text-white"/>
                    <span class="truncate">{{ $item['label'] }}</span>
                </a>
            @endforeach
        </nav>

        <div class="border-t border-white/10 px-5 py-3">
            <a href="{{ route('avisos') }}" class="text-xs font-medium text-inst-50/80 hover:text-white">Avisos institucionales</a>
            <span class="px-1 text-inst-50/40">·</span>
            <a href="{{ route('perfil') }}" class="text-xs font-medium text-inst-50/80 hover:text-white">Mi perfil</a>
        </div>
        <div class="border-t border-white/10 px-5 py-4">
            <p class="text-xs font-medium text-white">Sistema Estatal</p>
            <p class="mt-1 text-xs text-inst-50/70">Prototipo · Módulos en preparación</p>
        </div>
    </aside>

    <div class="min-h-screen lg:pl-72">
        <header class="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-slate-200 bg-white/95 px-4 backdrop-blur sm:px-6">
            <button type="button" id="sidebar-toggle" class="rounded-md p-2 text-tx-soft hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-inst-700 lg:hidden"
                    aria-label="Abrir menú" aria-controls="sidebar" aria-expanded="false">
                <x-icon name="menu"/>
            </button>
            <div class="flex items-center gap-2 text-sm text-tx-soft">
                <span>@yield('heading', 'Panel principal')</span>
            </div>
            <div class="ml-auto flex items-center gap-2">
                <div class="hidden w-64 md:block">
                    <label for="global-search" class="sr-only">Buscar</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400">
                            <x-icon name="search" class="h-4 w-4"/>
                        </span>
                        <input id="global-search" type="search" placeholder="Búsqueda disponible en otra fase" disabled
                               class="w-full rounded-md border-0 bg-slate-50 py-2 pl-9 pr-3 text-sm text-tx-soft ring-1 ring-inset ring-slate-200">
                    </div>
                </div>
                <a href="{{ route('avisos') }}" aria-label="Consultar avisos institucionales" title="Avisos institucionales"
                   class="inline-flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-xs font-medium text-tx-soft transition hover:bg-slate-50 hover:text-inst-900">
                    <x-icon name="bell" class="h-4 w-4"/>
                    <span class="hidden sm:inline">Avisos</span>
                </a>
                <button type="button" disabled title="La ayuda contextual aún no está disponible." aria-label="Ayuda no disponible"
                        class="rounded-md border border-slate-200 p-2 text-slate-400 disabled:cursor-not-allowed">
                    <x-icon name="info" class="h-4 w-4"/>
                </button>
                <span class="hidden items-center gap-2 rounded-full bg-inst-50 px-3 py-1.5 text-xs font-medium text-inst-700 sm:inline-flex">
                    <span class="h-2 w-2 rounded-full bg-estado-info"></span>
                    Entorno local
                </span>
                @if (auth()->user()?->getAttribute('local_demo_access'))
                    <span class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-900">
                        Demo local
                    </span>
                @else
                    @auth
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="rounded-md border border-slate-200 px-3 py-2 text-xs font-medium text-tx-soft transition hover:bg-slate-50 hover:text-inst-900">
                            Cerrar sesión
                        </button>
                    </form>
                    @endauth
                @endif
            </div>
        </header>

        <main id="view" class="mx-auto w-full max-w-screen-2xl p-4 sm:p-6 lg:p-8">
            @if (auth()->user()?->getAttribute('local_demo_access'))
                <div role="status" class="mb-5 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900">
                    Modo demo local de solo lectura. No se guardarán cambios, cargas ni confirmaciones.
                </div>
            @endif
            <div class="mb-6">
                <h1 class="text-xl font-semibold text-inst-900">@yield('heading', 'Panel principal')</h1>
                <p class="mt-1 text-sm text-tx-soft">@yield('subheading', 'Sistema Estatal LOC No. 139')</p>
            </div>
            @if (session('status'))
                <div role="status" class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif
            @if ($errors->any())
                <div role="alert" class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ $errors->first() }}
                </div>
            @endif
            @yield('content')
        </main>

        <footer class="border-t border-slate-200 px-4 py-4 text-center text-xs text-tx-soft sm:px-6">
            Sistema Estatal LOC No. 139 · Prototipo original preservado durante la preparación
        </footer>
    </div>

    @stack('scripts')
</body>
</html>
