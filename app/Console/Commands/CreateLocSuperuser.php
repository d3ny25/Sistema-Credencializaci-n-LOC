<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LocSuperuserProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateLocSuperuser extends Command
{
    protected $signature = 'loc:crear-superusuario';

    protected $description = 'Create or explicitly authorize a LOC superuser in loc_credencializacion.';

    public function handle(LocSuperuserProvisioner $provisioner): int
    {
        try {
            $database = $provisioner->verifyTarget();
        } catch (\PDOException|\RuntimeException) {
            $this->error('No se pudo verificar la conexión MySQL/MariaDB y el esquema users en loc_credencializacion. No se modificó ningún usuario.');

            return self::FAILURE;
        }

        $this->info("Base de datos verificada: {$database}");

        $email = mb_strtolower(trim((string) $this->ask('Correo electrónico institucional')));
        $emailValidator = Validator::make(['email' => $email], [
            'email' => ['required', 'email', 'max:255'],
        ]);

        if ($emailValidator->fails()) {
            $this->error('El correo electrónico no tiene un formato válido.');

            return self::FAILURE;
        }

        try {
            $existingUser = $provisioner->findByEmail($email);
        } catch (\PDOException|\RuntimeException) {
            $this->error('No fue posible consultar la cuenta en la base verificada. No se modificó ningún usuario.');

            return self::FAILURE;
        }

        if ($existingUser instanceof User) {
            if ($existingUser->role === 'superadmin') {
                $this->info('La cuenta ya tiene el rol superadmin. No se realizaron cambios.');

                return self::SUCCESS;
            }

            $this->warn('Ya existe una cuenta con ese correo.');
            $this->warn('No se cambiarán su contraseña, nombre ni otros atributos.');

            if ($this->ask("Escribe ASIGNAR SUPERADMIN A {$email} para confirmar") !== "ASIGNAR SUPERADMIN A {$email}") {
                $this->info('Operación cancelada; no se realizaron cambios.');

                return self::SUCCESS;
            }

            try {
                $provisioner->assignSuperadminRole($existingUser);
            } catch (\PDOException|\RuntimeException) {
                $this->error('No fue posible asignar el rol. No se cambió la contraseña.');

                return self::FAILURE;
            }

            $this->info('Se asignó el rol superadmin a la cuenta existente; la contraseña no se modificó.');

            return self::SUCCESS;
        }

        $name = trim((string) $this->ask('Nombre completo'));
        $password = (string) $this->secret('Contraseña (mínimo 6 caracteres)');
        $passwordConfirmation = (string) $this->secret('Confirma la contraseña');
        $validator = Validator::make([
            'name' => $name,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'password' => [
                'required',
                'confirmed',
                Password::min(6)->mixedCase()->numbers()->symbols(),
            ],
        ], [
            'password.required' => 'La contraseña es obligatoria.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres.',
            'password.mixed' => 'La contraseña debe incluir al menos una letra mayúscula y una minúscula.',
            'password.numbers' => 'La contraseña debe incluir al menos un número.',
            'password.symbols' => 'La contraseña debe incluir al menos un símbolo, por ejemplo !, @ o #.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        if ($this->ask("Escribe CREAR SUPERUSUARIO EN {$database} para confirmar") !== "CREAR SUPERUSUARIO EN {$database}") {
            $this->info('Operación cancelada; no se creó ninguna cuenta.');

            return self::SUCCESS;
        }

        try {
            $provisioner->create($name, $email, $password);
        } catch (\PDOException|\RuntimeException) {
            $this->error('No fue posible crear la cuenta. Verifica si otra operación creó el correo y vuelve a ejecutar el diagnóstico; no se mostró la excepción de base de datos.');

            return self::FAILURE;
        }

        $this->info('Cuenta superadmin creada. La contraseña se guardó con el hash de Laravel y no se mostró.');

        return self::SUCCESS;
    }
}
