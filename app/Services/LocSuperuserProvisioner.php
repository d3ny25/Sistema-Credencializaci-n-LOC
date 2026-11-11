<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;

class LocSuperuserProvisioner
{
    private const TARGET_DATABASE = 'loc_credencializacion';

    public function verifyTarget(): string
    {
        $connectionName = (string) config('database.default');
        $connectionConfig = config("database.connections.{$connectionName}");
        $driver = $connectionConfig['driver'] ?? null;

        if (! in_array($driver, ['mysql', 'mariadb'], true)
            || ($connectionConfig['database'] ?? null) !== self::TARGET_DATABASE) {
            throw new RuntimeException('La conexión configurada no es la base LOC autorizada.');
        }

        $connection = DB::connection($connectionName);
        $pdoDriver = $connection->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $selectedDatabase = $connection->selectOne('SELECT DATABASE() AS database_name')?->database_name;

        if ($pdoDriver !== 'mysql' || $selectedDatabase !== self::TARGET_DATABASE) {
            throw new RuntimeException('El servidor respondió con un destino diferente al autorizado.');
        }

        if (! Schema::connection($connectionName)->hasColumns('users', ['name', 'email', 'password', 'role'])) {
            throw new RuntimeException('La tabla users no contiene las columnas requeridas para los roles actuales.');
        }

        return self::TARGET_DATABASE;
    }

    public function findByEmail(string $email): ?User
    {
        $normalizedEmail = mb_strtolower(trim($email));

        return $this->userQuery()->whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();
    }

    public function assignSuperadminRole(User $user): void
    {
        DB::connection($user->getConnectionName())->transaction(function () use ($user): void {
            $lockedUser = $this->userQuery()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedUser->role = 'superadmin';
            $lockedUser->save();
        });
    }

    public function create(string $name, string $email, string $password): User
    {
        $connectionName = (string) config('database.default');

        return DB::connection($connectionName)->transaction(function () use ($connectionName, $name, $email, $password): User {
            $user = new User;
            $user->setConnection($connectionName);
            $user->name = $name;
            $user->email = $email;
            $user->password = Hash::make($password);
            $user->role = 'superadmin';
            $user->save();

            return $user;
        });
    }

    private function userQuery()
    {
        $connectionName = (string) config('database.default');

        return (new User)->setConnection($connectionName)->newQuery();
    }
}
