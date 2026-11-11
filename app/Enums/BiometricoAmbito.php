<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\LocCatalogoOrganizacional;
use App\Models\LocCatalogoVersion;

enum BiometricoAmbito: string
{
    case ESTATALES = 'ESTATALES';
    case PREVENCION = 'PREVENCION';
    case NO_RESUELTO = 'NO_RESUELTO';

    public function label(): string
    {
        return match ($this) {
            self::ESTATALES => 'Estatales (Policía Estatal)',
            self::PREVENCION => 'Prevención (Centros Penitenciarios)',
            self::NO_RESUELTO => 'No Resuelto (Pendiente de Aclaración)',
        };
    }

    public function isResuelto(): bool
    {
        return $this !== self::NO_RESUELTO;
    }

    public function canAssociateExpediente(): bool
    {
        return $this->isResuelto();
    }

    /**
     * Resuelve el ámbito biométrico de forma determinista evaluando los datos institucionales
     * del registro en el catálogo activo vigente.
     *
     * Reglas institucionales comprobadas:
     * 1. Si la clave o datos están vacíos -> NO_RESUELTO.
     * 2. Si el registro está inactivo o la versión no es vigente -> NO_RESUELTO.
     * 3. Si la dirección general está vacía o es desconocida -> NO_RESUELTO.
     * 4. Si direccion_general == 'DIR.GRAL. PREV. Y REI. SOC.' y coordinacion == 'DIR. PREV.'
     *    y la clave pertenece exactamente a D1..D27 -> PREVENCION.
     * 5. Si direccion_general pertenece a las 9 Direcciones Estatales reconocidas, Municipios
     *    o CUSAEM (y no es clave D penitenciaria) -> ESTATALES.
     * 6. En cualquier otro caso -> NO_RESUELTO (SIN fallbacks por expresiones regulares ni LIKE).
     */
    public static function fromClaveCatalogo(
        ?string $clave,
        ?string $direccionGeneral = null,
        ?string $coordinacion = null,
        bool $activo = true,
        ?bool $esVigente = true
    ): self {
        if ($clave === null || trim($clave) === '') {
            return self::NO_RESUELTO;
        }

        // Si el registro está inactivo o la versión del catálogo no es vigente -> NO_RESUELTO
        if (! $activo || $esVigente === false) {
            return self::NO_RESUELTO;
        }

        // Si la Dirección General está vacía o no fue provista -> NO_RESUELTO (Se prohíben fallbacks por regex)
        if ($direccionGeneral === null || trim($direccionGeneral) === '') {
            return self::NO_RESUELTO;
        }

        $claveUpper = strtoupper(trim($clave));
        $dirUpper = strtoupper(trim($direccionGeneral));
        $coordUpper = $coordinacion !== null ? strtoupper(trim($coordinacion)) : '';

        // Comprobación de Prevención (Centros Penitenciarios D1..D27)
        if ($dirUpper === 'DIR.GRAL. PREV. Y REI. SOC.' && $coordUpper === 'DIR. PREV.') {
            if (preg_match('/^D([1-9]|1[0-9]|2[0-7])$/', $claveUpper)) {
                return self::PREVENCION;
            }

            return self::NO_RESUELTO;
        }

        // Si la clave tiene prefijo D pero no cumple la estructura organizacional comprobada -> NO_RESUELTO
        if (str_starts_with($claveUpper, 'D')) {
            return self::NO_RESUELTO;
        }

        // Direcciones Estatales reconocidas institucionalmente
        $direccionesEstatales = [
            'DIR. GRAL. SEG. PUB. Y TTO.',
            'DIR. GRAL. POL. PROC.',
            'DIR. GRAL. COM. ROB. VEH. Y TTE.',
            'DIR. GRAL. INF.',
            'DIR. GRAL. ASUNTOS JURIDICOS',
            'SUBDIR. ASUNTOS INTERNOS',
            'SEC. EJEC. SIS. EST. SEG. PUB.',
            'SUBSEC. POL. ESTATAL',
            'SECRETARIA DE SEGURIDAD',
            'MUNICIPIOS',
            'CUSAEM',
            'COORD. GPOS. TACTICOS',
        ];

        if (in_array($dirUpper, $direccionesEstatales, true)) {
            return self::ESTATALES;
        }

        return self::NO_RESUELTO;
    }

    /**
     * Resuelve el ámbito biométrico directamente desde una instancia del catálogo organizacional.
     */
    public static function fromCatalogoRecord(
        ?LocCatalogoOrganizacional $record,
        ?LocCatalogoVersion $version = null
    ): self {
        if ($record === null) {
            return self::NO_RESUELTO;
        }

        if (! $record->activo) {
            return self::NO_RESUELTO;
        }

        $esVigente = $version !== null ? (bool) $version->es_vigente : true;

        return self::fromClaveCatalogo(
            $record->clave_origen,
            $record->direccion_general,
            $record->coordinacion,
            (bool) $record->activo,
            $esVigente
        );
    }
}
