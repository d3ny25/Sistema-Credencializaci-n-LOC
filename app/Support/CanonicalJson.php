<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * JSON canónico para evidencia (validado en la Fase 1B.1, vector c8b1a941…947db3).
 *
 * Reglas: UTF-8 sin BOM; claves ASCII imprimibles ordenadas recursivamente (ksort SORT_STRING);
 * los arreglos-lista conservan su orden; sin espacios; JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE |
 * JSON_PRESERVE_ZERO_FRACTION; Unicode sin normalizar (se hashea lo que se renderiza);
 * null, cadena vacía y clave ausente son distintos. Los bytes producidos son la referencia:
 * para verificar se hashea el texto guardado, nunca una recodificación.
 */
final class CanonicalJson
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Solo para pruebas: sustituye a ini_set() para simular un entorno que no permite cambiar la directiva.
     *
     * @var (callable(string, string): (string|false))|null
     */
    public static $establecerDirectiva = null;

    public static function encode(mixed $valor): string
    {
        // serialize_precision=-1 da la representación más corta que reconstruye el mismo double. Se fija
        // aquí y se restaura después, sin depender del php.ini; si no puede fijarse, se falla explícitamente
        // en lugar de producir un hash no canónico.
        $establecer = self::$establecerDirectiva ?? 'ini_set';
        $anterior = ini_get('serialize_precision');
        if ($establecer('serialize_precision', '-1') === false || (string) ini_get('serialize_precision') !== '-1') {
            throw new \RuntimeException('No fue posible fijar serialize_precision=-1: la canonicalización no puede garantizarse.');
        }

        try {
            return json_encode(self::normalizar($valor), self::FLAGS);
        } finally {
            ini_set('serialize_precision', (string) $anterior);
        }
    }

    public static function hash(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    private static function normalizar(mixed $valor): mixed
    {
        if (is_array($valor)) {
            if (array_is_list($valor)) {
                return array_map(self::normalizar(...), $valor);
            }
            foreach (array_keys($valor) as $clave) {
                if (! is_string($clave) || preg_match('/[^\x20-\x7E]/', $clave) === 1) {
                    throw new InvalidArgumentException('Las claves del JSON canónico deben ser texto ASCII.');
                }
            }
            ksort($valor, SORT_STRING);

            return array_map(self::normalizar(...), $valor);
        }

        if (is_float($valor) && (! is_finite($valor) || abs($valor) >= 1e15 || ($valor !== 0.0 && abs($valor) < 1e-6))) {
            // Fuera de este rango PHP usa exponentes que otros lenguajes escriben distinto (1B.1, C12).
            throw new InvalidArgumentException('Número decimal fuera del rango admitido por el JSON canónico.');
        }

        if (is_object($valor) || is_resource($valor)) {
            throw new InvalidArgumentException('El JSON canónico solo admite escalares, null y arreglos.');
        }

        return $valor;
    }
}
