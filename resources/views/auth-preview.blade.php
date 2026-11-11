<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acceso · Sistema Estatal LOC 139</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="inst-pattern flex min-h-full items-center justify-center bg-inst-900 px-4 py-10">
    <main class="w-full max-w-md overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-slate-900/10">
        <header class="inst-pattern flex items-center gap-3 bg-inst-900 px-6 py-5 text-white">
            <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-white/10 ring-1 ring-white/15">
                <x-icon name="shield" class="h-6 w-6"/>
            </span>
            <div>
                <p class="text-sm font-semibold">Sistema Estatal</p>
                <p class="text-xs text-inst-50/75">Credenciales · LOC N.º 139</p>
            </div>
        </header>
        <div class="p-6 sm:p-7">
            <h1 class="text-xl font-semibold text-inst-900">Inicio de sesión</h1>
            <p class="mt-2 text-sm leading-6 text-tx-soft">Ingresa con la cuenta institucional que te haya sido asignada.</p>
            @if ($errors->any())
                <div role="alert" class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ $errors->first() }}
                </div>
            @endif
            <form class="mt-5 space-y-4" method="POST" action="{{ route('login.store') }}">
                @csrf
                <label for="email" class="block text-xs font-medium text-tx-soft">Correo electrónico
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                        class="mt-1 w-full rounded-md border-0 bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inst-700">
                </label>
                <label for="password" class="block text-xs font-medium text-tx-soft">Contraseña
                    <input id="password" name="password" type="password" required autocomplete="current-password"
                        class="mt-1 w-full rounded-md border-0 bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inst-700">
                </label>
                <label for="remember" class="flex items-center gap-2 text-sm text-tx-soft">
                    <input id="remember" name="remember" type="checkbox" value="1" class="rounded border-slate-300 text-inst-700 focus:ring-inst-700">
                    Mantener la sesión iniciada
                </label>
                <button type="submit" class="w-full rounded-md bg-inst-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-inst-800 focus:outline-none focus:ring-2 focus:ring-inst-700 focus:ring-offset-2">
                    Iniciar sesión
                </button>
            </form>
        </div>
    </main>
</body>
</html>
