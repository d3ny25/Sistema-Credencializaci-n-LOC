<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\LocCatalogoOrganizacional;
use App\Models\SolicitudElemento;
use Illuminate\Support\Collection;

class AgrupamientoInstitucional
{
    /**
     * Cache estática en memoria durante la petición para evitar consultas redundantes.
     *
     * @var Collection<int, LocCatalogoOrganizacional>|null
     */
    private static ?Collection $catalogoCache = null;

    /**
     * Resuelve el estado institucional del agrupamiento para un elemento de solicitud.
     *
     * Implementa de forma estricta los 4 escenarios institucionales:
     * 1. Clave verificada ('verificado'): Coincidencia unívoca y verificable en catálogo.
     * 2. Texto origen sin clave ('sin_clave'): Texto original presente pero sin coincidencia en catálogo activo.
     * 3. Ambiguo o discrepante ('discrepante' | 'ambiguo'):
     *    - 'discrepante': Texto solicitado en Excel difiere de la plantilla oficial de la persona.
     *    - 'ambiguo': Múltiples registros activos con claves distintas coinciden con el texto.
     * 4. Sin información ('sin_informacion'): Sin datos en solicitud ni en plantilla.
     *
     * @param  SolicitudElemento  $elemento
     * @param  Collection<int, LocCatalogoOrganizacional>|null  $catalogo
     * @return array{
     *     estado: string,
     *     clave: string|null,
     *     denominacion: string|null,
     *     solicitado: string|null,
     *     persona: string|null,
     *     advertencia: string|null,
     *     fuente: string
     * }
     */
    public static function resolver(SolicitudElemento $elemento, ?Collection $catalogo = null): array
    {
        $solicitado = trim((string) (
            $elemento->datos_solicitados['adscripcion']
            ?? $elemento->datos_solicitados['agrupamiento']
            ?? ''
        ));

        $personaAgr = trim((string) ($elemento->persona?->agrupamiento ?? ''));

        // Escenario 4: Sin información
        if ($solicitado === '' && $personaAgr === '') {
            return [
                'estado' => 'sin_informacion',
                'clave' => null,
                'denominacion' => null,
                'solicitado' => null,
                'persona' => null,
                'advertencia' => null,
                'fuente' => 'ninguna',
            ];
        }

        // Escenario 3A: Discrepante entre Excel y plantilla de personal
        if ($solicitado !== '' && $personaAgr !== '' && strtoupper($solicitado) !== strtoupper($personaAgr)) {
            return [
                'estado' => 'discrepante',
                'clave' => null,
                'denominacion' => null,
                'solicitado' => $solicitado,
                'persona' => $personaAgr,
                'advertencia' => 'Discrepancia entre la adscripción solicitada en Excel y el registro oficial de la persona en plantilla.',
                'fuente' => 'solicitud_y_plantilla',
            ];
        }

        $textoAValidar = $solicitado !== '' ? $solicitado : $personaAgr;
        $normTexto = strtoupper($textoAValidar);

        // Obtener el catálogo activo (usando el provisto, la cache estática o consultando la base de datos)
        $catalogoActivo = $catalogo ?? self::obtenerCatalogo();

        // Búsqueda determinista exacta en catálogo activo (sin coincidencias difusas ni aproximaciones)
        $matches = $catalogoActivo->filter(function ($item) use ($normTexto): bool {
            $nombre = strtoupper(trim((string) ($item->nombre ?? '')));
            $agrupamiento = strtoupper(trim((string) ($item->agrupamiento ?? '')));

            return $nombre === $normTexto || $agrupamiento === $normTexto;
        });

        // Escenario 3B: Ambiguo por homónimos con claves distintas
        $clavesDistintas = $matches->pluck('clave_origen')->filter()->unique();
        if ($clavesDistintas->count() > 1) {
            return [
                'estado' => 'ambiguo',
                'clave' => null,
                'denominacion' => null,
                'solicitado' => $solicitado ?: null,
                'persona' => $personaAgr ?: null,
                'advertencia' => 'Múltiples claves institucionales coinciden con esta denominación (' . $clavesDistintas->implode(', ') . '). No se asigna clave automática.',
                'fuente' => 'catalogo_ambiguo',
            ];
        }

        // Escenario 1: Clave verificada
        if ($matches->count() >= 1) {
            $match = $matches->first();

            return [
                'estado' => 'verificado',
                'clave' => (string) $match->clave_origen,
                'denominacion' => (string) ($match->nombre ?: $match->agrupamiento),
                'solicitado' => $solicitado ?: null,
                'persona' => $personaAgr ?: null,
                'advertencia' => null,
                'fuente' => 'catalogo_vigente',
            ];
        }

        // Escenario 2: Texto origen sin clave
        return [
            'estado' => 'sin_clave',
            'clave' => null,
            'denominacion' => null,
            'solicitado' => $solicitado ?: null,
            'persona' => $personaAgr ?: null,
            'advertencia' => 'Texto de origen sin clave institucional verificada en el catálogo oficial.',
            'fuente' => $solicitado !== '' ? 'excel_origen' : 'plantilla_personal',
        ];
    }

    /**
     * Obtiene el catálogo organizacional activo.
     *
     * @return Collection<int, LocCatalogoOrganizacional>
     */
    public static function obtenerCatalogo(): Collection
    {
        if (self::$catalogoCache === null) {
            self::$catalogoCache = LocCatalogoOrganizacional::query()
                ->where('activo', true)
                ->get(['id', 'clave_origen', 'nombre', 'agrupamiento', 'coordinacion']);
        }

        return self::$catalogoCache;
    }

    /**
     * Limpia la cache estática (útil en pruebas).
     */
    public static function limpiarCache(): void
    {
        self::$catalogoCache = null;
    }
}
