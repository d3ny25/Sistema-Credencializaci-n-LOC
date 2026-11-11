<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Storage\SafeReadOnlyLocalAdapter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use League\Flysystem\Filesystem;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $isLocalDemo = static fn (User $user): bool => (bool) $user->getAttribute('local_demo_access')
            && app()->environment('local')
            && config('local-demo.enabled');
        $isSuperAdministrator = static fn (User $user): bool => $user->role === 'superadmin'
            && ! $isLocalDemo($user);

        Gate::define('access-administration', static fn (User $user): bool => $isSuperAdministrator($user) || $isLocalDemo($user));

        Gate::define('view-biometrics', static function (User $user) use ($isLocalDemo): bool {
            if ((bool) $user->getAttribute('local_demo_access') || $user->role === 'local_demo' || $isLocalDemo($user)) {
                return false;
            }

            return in_array($user->role, ['superadmin', 'admin', 'administrador', 'supervisor', 'credencializador', 'impresion', 'operador'], true);
        });

        Gate::define('update-biometrics', static function (User $user) use ($isLocalDemo): bool {
            if ((bool) $user->getAttribute('local_demo_access') || $user->role === 'local_demo' || $isLocalDemo($user)) {
                return false;
            }

            return in_array($user->role, ['superadmin', 'admin', 'administrador', 'supervisor', 'credencializador', 'impresion'], true);
        });

        Storage::extend('safe-local-readonly', static function ($app, array $config) {
            $root = $config['root'] ?? null;
            if ($root === null || trim((string) $root) === '') {
                throw new \InvalidArgumentException(
                    'El disco "biometricos" requiere una ruta de almacenamiento institucional válida configurada en la variable BIOMETRICOS_STORAGE_PATH.'
                );
            }

            $adapter = new SafeReadOnlyLocalAdapter(
                location: (string) $root,
                linkHandling: SafeReadOnlyLocalAdapter::DISALLOW_LINKS,
                lazyRootCreation: true
            );

            return new FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config
            );
        });

        RateLimiter::for('login', static function (Request $request): array {
            $identity = Str::transliterate(Str::lower((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by($identity.'|'.$request->ip()),
                Limit::perMinute(30)->by($request->ip()),
            ];
        });

        Gate::define(
            'view-credential-template-versions',
            static fn (User $user): bool => $isLocalDemo($user) || $isSuperAdministrator($user) || in_array(
                (int) $user->getKey(),
                array_merge(
                    config('credential-templates.editor_user_ids', []),
                    config('credential-templates.approver_user_ids', []),
                ),
                true,
            ),
        );

        Gate::define(
            'manage-credential-templates',
            static fn (User $user): bool => $isSuperAdministrator($user) || (! $isLocalDemo($user) && in_array(
                (int) $user->getKey(),
                config('credential-templates.editor_user_ids', []),
                true,
            )),
        );

        Gate::define(
            'approve-credential-templates',
            static fn (User $user): bool => $isSuperAdministrator($user) || (! $isLocalDemo($user) && in_array(
                (int) $user->getKey(),
                config('credential-templates.approver_user_ids', []),
                true,
            )),
        );

        Gate::define(
            'view-devices',
            static fn (User $user): bool => $isLocalDemo($user) || $isSuperAdministrator($user) || in_array(
                (int) $user->getKey(),
                array_merge(
                    config('devices.permissions.viewer_user_ids', []),
                    config('devices.permissions.manager_user_ids', []),
                    config('devices.permissions.tester_user_ids', []),
                    config('devices.permissions.administrator_user_ids', []),
                ),
                true,
            ),
        );

        Gate::define(
            'manage-devices',
            static fn (User $user): bool => ! $isLocalDemo($user) && ($isSuperAdministrator($user) || in_array(
                (int) $user->getKey(),
                config('devices.permissions.administrator_user_ids', []),
                true,
            ) || in_array(
                (int) $user->getKey(),
                config('devices.permissions.manager_user_ids', []),
                true,
            )),
        );

        Gate::define(
            'test-devices',
            static fn (User $user): bool => ! $isLocalDemo($user) && ($isSuperAdministrator($user) || in_array(
                (int) $user->getKey(),
                config('devices.permissions.administrator_user_ids', []),
                true,
            ) || in_array(
                (int) $user->getKey(),
                config('devices.permissions.tester_user_ids', []),
                true,
            )),
        );

        Gate::define(
            'administer-devices',
            static fn (User $user): bool => ! $isLocalDemo($user) && ($isSuperAdministrator($user) || in_array(
                (int) $user->getKey(),
                config('devices.permissions.administrator_user_ids', []),
                true,
            )),
        );
    }
}
