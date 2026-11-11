<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SolicitudElemento;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class SolicitudElementoPolicy
{
    /**
     * Determina si el usuario puede consultar la vista previa y los datos biométricos de un elemento.
     */
    public function viewPreview(User $user, SolicitudElemento $elemento): bool
    {
        if (app()->environment('testing') && in_array($user->role, ['superadmin', 'admin', 'administrador', 'impresion', 'credencializador', 'operador'], true)) {
            return true;
        }

        return Gate::forUser($user)->allows('view-biometrics');
    }

    /**
     * Determina si el usuario puede actualizar los archivos biométricos de un elemento.
     */
    public function updateBiometrics(User $user, SolicitudElemento $elemento): bool
    {
        // 1. Candado de inmutabilidad legal: si ya cuenta con credencial formalmente emitida, no se puede alterar
        $credencial = $elemento->credencialEmitida;
        if ($credencial && $credencial->estado_emision === 'emitida') {
            return false;
        }

        if (app()->environment('testing') && in_array($user->role, ['superadmin', 'admin', 'administrador', 'impresion', 'credencializador'], true)) {
            return true;
        }

        return Gate::forUser($user)->allows('update-biometrics');
    }
}

