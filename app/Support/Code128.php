<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Generador mínimo de Code 128 (subconjunto B: ASCII 32–126) como SVG en línea.
 * Sin dependencias; la consistencia de la tabla se verifica en CredencialEmisionTest.
 */
final class Code128
{
    /** Anchos barra/espacio por valor de símbolo (0–106); 104 = Start B, 106 = Stop. */
    public const PATRONES = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const START_B = 104;
    private const STOP = 106;
    private const ZONA_QUIETA = 10;

    /**
     * Valores de símbolo (Start B, datos, checksum, Stop) o null si el texto no es codificable en el subconjunto B.
     *
     * @return list<int>|null
     */
    public static function valoresSeguros(string $texto): ?array
    {
        if ($texto === '' || preg_match('/^[\x20-\x7E]+$/', $texto) !== 1) {
            return null;
        }

        $valores = [self::START_B];
        $suma = self::START_B;
        foreach (str_split($texto) as $i => $caracter) {
            $valor = ord($caracter) - 32;
            $valores[] = $valor;
            $suma += $valor * ($i + 1);
        }
        $valores[] = $suma % 103;
        $valores[] = self::STOP;

        return $valores;
    }

    /** @return list<int> */
    public static function valores(string $texto): array
    {
        return self::valoresSeguros($texto) ?? throw new \InvalidArgumentException('Texto no codificable en Code 128 B.');
    }

    /** Ancho total en módulos, incluidas las zonas quietas. */
    public static function modulos(string $texto): int
    {
        $total = 2 * self::ZONA_QUIETA;
        foreach (self::valores($texto) as $valor) {
            $total += array_sum(array_map('intval', str_split(self::PATRONES[$valor])));
        }

        return $total;
    }

    /**
     * SVG escalable (viewBox en módulos) o null si el texto no es codificable.
     */
    public static function svg(string $texto, string $clase = ''): ?string
    {
        $valores = self::valoresSeguros($texto);
        if ($valores === null) {
            return null;
        }

        $x = self::ZONA_QUIETA;
        $barras = '';
        foreach ($valores as $valor) {
            foreach (str_split(self::PATRONES[$valor]) as $j => $ancho) {
                $ancho = (int) $ancho;
                if ($j % 2 === 0) {
                    $barras .= '<rect x="'.$x.'" y="0" width="'.$ancho.'" height="40"/>';
                }
                $x += $ancho;
            }
        }
        $total = $x + self::ZONA_QUIETA;

        return '<svg xmlns="http://www.w3.org/2000/svg" class="'.e($clase).'" viewBox="0 0 '.$total.' 40" preserveAspectRatio="none"'
            .' shape-rendering="crispEdges" fill="currentColor" role="img" aria-label="Código de barras '.e($texto).'" data-code128="'.e($texto).'">'
            .$barras.'</svg>';
    }
}
