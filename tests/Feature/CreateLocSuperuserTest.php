<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LocSuperuserProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class CreateLocSuperuserTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_a_superuser_only_after_target_and_final_confirmation(): void
    {
        $provisioner = $this->mock(LocSuperuserProvisioner::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verifyTarget')->once()->andReturn('loc_credencializacion');
            $mock->shouldReceive('findByEmail')->once()->with('admin@example.test')->andReturnNull();
            $mock->shouldReceive('create')
                ->once()
                ->with('Operador institucional', 'admin@example.test', 'Aa1!bc');
        });

        $this->artisan('loc:crear-superusuario')
            ->expectsOutputToContain('Base de datos verificada: loc_credencializacion')
            ->expectsQuestion('Correo electrónico institucional', 'admin@example.test')
            ->expectsQuestion('Nombre completo', 'Operador institucional')
            ->expectsQuestion('Contraseña (mínimo 6 caracteres)', 'Aa1!bc')
            ->expectsQuestion('Confirma la contraseña', 'Aa1!bc')
            ->expectsQuestion('Escribe CREAR SUPERUSUARIO EN loc_credencializacion para confirmar', 'CREAR SUPERUSUARIO EN loc_credencializacion')
            ->expectsOutputToContain('Cuenta superadmin creada')
            ->assertExitCode(0);
    }

    public function test_existing_user_is_only_promoted_after_explicit_confirmation_without_password_prompt(): void
    {
        $existingUser = new User(['name' => 'Existing user', 'email' => 'existing@example.test']);
        $existingUser->setAttribute('role', 'operator');

        $this->mock(LocSuperuserProvisioner::class, function (MockInterface $mock) use ($existingUser): void {
            $mock->shouldReceive('verifyTarget')->once()->andReturn('loc_credencializacion');
            $mock->shouldReceive('findByEmail')->once()->with('existing@example.test')->andReturn($existingUser);
            $mock->shouldReceive('assignSuperadminRole')->once()->with($existingUser);
            $mock->shouldNotReceive('create');
        });

        $this->artisan('loc:crear-superusuario')
            ->expectsQuestion('Correo electrónico institucional', 'existing@example.test')
            ->expectsOutputToContain('No se cambiarán su contraseña')
            ->expectsQuestion('Escribe ASIGNAR SUPERADMIN A existing@example.test para confirmar', 'ASIGNAR SUPERADMIN A existing@example.test')
            ->expectsOutputToContain('la contraseña no se modificó')
            ->assertExitCode(0);
    }

    public function test_existing_user_is_not_changed_when_promotion_is_not_confirmed(): void
    {
        $existingUser = new User(['name' => 'Existing user', 'email' => 'existing@example.test']);
        $existingUser->setAttribute('role', 'operator');

        $this->mock(LocSuperuserProvisioner::class, function (MockInterface $mock) use ($existingUser): void {
            $mock->shouldReceive('verifyTarget')->once()->andReturn('loc_credencializacion');
            $mock->shouldReceive('findByEmail')->once()->with('existing@example.test')->andReturn($existingUser);
            $mock->shouldNotReceive('assignSuperadminRole');
            $mock->shouldNotReceive('create');
        });

        $this->artisan('loc:crear-superusuario')
            ->expectsQuestion('Correo electrónico institucional', 'existing@example.test')
            ->expectsQuestion('Escribe ASIGNAR SUPERADMIN A existing@example.test para confirmar', 'cancelar')
            ->expectsOutputToContain('Operación cancelada')
            ->assertExitCode(0);

        $this->assertSame('operator', $existingUser->role);
    }

    public function test_existing_superadmin_is_left_unchanged(): void
    {
        $existingUser = new User(['name' => 'Existing admin', 'email' => 'admin@example.test']);
        $existingUser->setAttribute('role', 'superadmin');

        $this->mock(LocSuperuserProvisioner::class, function (MockInterface $mock) use ($existingUser): void {
            $mock->shouldReceive('verifyTarget')->once()->andReturn('loc_credencializacion');
            $mock->shouldReceive('findByEmail')->once()->with('admin@example.test')->andReturn($existingUser);
            $mock->shouldNotReceive('assignSuperadminRole');
            $mock->shouldNotReceive('create');
        });

        $this->artisan('loc:crear-superusuario')
            ->expectsQuestion('Correo electrónico institucional', 'admin@example.test')
            ->expectsOutputToContain('ya tiene el rol superadmin')
            ->assertExitCode(0);
    }

    public function test_command_aborts_before_prompts_when_database_target_cannot_be_verified(): void
    {
        $this->mock(LocSuperuserProvisioner::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verifyTarget')->once()->andThrow(new \RuntimeException('private failure detail'));
            $mock->shouldNotReceive('findByEmail');
            $mock->shouldNotReceive('create');
        });

        $this->artisan('loc:crear-superusuario')
            ->expectsOutputToContain('No se pudo verificar la conexión MySQL/MariaDB')
            ->assertExitCode(1);
    }

    public function test_invalid_password_never_reaches_the_provisioner(): void
    {
        $this->mock(LocSuperuserProvisioner::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verifyTarget')->once()->andReturn('loc_credencializacion');
            $mock->shouldReceive('findByEmail')->once()->with('admin@example.test')->andReturnNull();
            $mock->shouldNotReceive('create');
        });

        $this->artisan('loc:crear-superusuario')
            ->expectsQuestion('Correo electrónico institucional', 'admin@example.test')
            ->expectsQuestion('Nombre completo', 'Operador institucional')
            ->expectsQuestion('Contraseña (mínimo 6 caracteres)', 'short')
            ->expectsQuestion('Confirma la contraseña', 'short')
            ->expectsOutputToContain('La contraseña debe tener al menos 6 caracteres.')
            ->expectsOutputToContain('La contraseña debe incluir al menos una letra mayúscula y una minúscula.')
            ->expectsOutputToContain('La contraseña debe incluir al menos un número.')
            ->expectsOutputToContain('La contraseña debe incluir al menos un símbolo')
            ->assertExitCode(1);
    }

    public function test_provisioner_rejects_sqlite_and_wrong_database_before_connecting(): void
    {
        $originalConnection = config('database.default');
        $originalMysqlConfig = config('database.connections.mysql');

        try {
            config(['database.default' => 'sqlite']);

            try {
                app(LocSuperuserProvisioner::class)->verifyTarget();
                $this->fail('SQLite debe rechazarse para el aprovisionamiento real.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('La conexión configurada no es la base LOC autorizada.', $exception->getMessage());
            }

            config([
                'database.default' => 'mysql',
                'database.connections.mysql.driver' => 'mysql',
                'database.connections.mysql.database' => 'another_database',
            ]);

            try {
                app(LocSuperuserProvisioner::class)->verifyTarget();
                $this->fail('Una base distinta debe rechazarse antes de conectar.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('La conexión configurada no es la base LOC autorizada.', $exception->getMessage());
            }
        } finally {
            config([
                'database.default' => $originalConnection,
                'database.connections.mysql' => $originalMysqlConfig,
            ]);
        }
    }

    public function test_provisioner_hashes_new_password_and_promotes_existing_user_without_changing_it(): void
    {
        $provisioner = app(LocSuperuserProvisioner::class);
        $created = $provisioner->create(
            'Authorized administrator',
            'authorized@example.test',
            'Secure-Only-Test-Password-284!',
        );

        $this->assertSame('superadmin', $created->role);
        $this->assertTrue(password_verify('Secure-Only-Test-Password-284!', $created->password));
        $this->assertNotSame('Secure-Only-Test-Password-284!', $created->password);

        $existing = User::factory()->create([
            'role' => 'operator',
            'password' => 'existing-private-password',
        ]);
        $existingPasswordHash = $existing->password;

        $provisioner->assignSuperadminRole($existing);

        $this->assertSame('superadmin', $existing->fresh()->role);
        $this->assertSame($existingPasswordHash, $existing->fresh()->password);
    }
}
