<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class LocalDemoAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('local') || ! config('local-demo.enabled') || Auth::check()) {
            return $next($request);
        }

        $connection = (string) config('database.default');
        $database = strtolower((string) config("database.connections.{$connection}.database"));
        abort_if(
            in_array($connection, ['mysql', 'mariadb'], true) && $database === 'loc_credencializacion',
            403,
            'El acceso demo está bloqueado para la base operativa loc_credencializacion.'
        );

        abort_unless(
            in_array($request->method(), ['GET', 'HEAD'], true),
            403,
            'El modo demo local es de solo lectura; esta acción requiere autenticación real.'
        );

        $user = new User;
        $user->setRawAttributes([
            'id' => 0,
            'name' => 'Acceso de demostración local',
            'email' => 'local-demo@invalid.test',
            'role' => 'local_demo',
            'local_demo_access' => true,
        ]);

        Auth::guard()->setUser($user);

        try {
            return $next($request);
        } finally {
            Auth::forgetGuards();
        }
    }
}
