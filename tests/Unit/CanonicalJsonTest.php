<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CanonicalJson;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Fase 1B.2 — Canonicalización del manifiesto. Datos 100 % ficticios, idénticos a los del script de
 * validación de la Fase 1B.1 (canon.php), cuyo resultado se verificó contra una implementación
 * independiente en Python.
 */
class CanonicalJsonTest extends TestCase
{
    private const VECTOR_MANIFIESTO = 'c8b1a941f49ef5eb0837d45462c9ed5f35f662233f5cdf510e00931dab947db3';
    private const VECTOR_PLANTILLA = 'dd53c1431523b1483a10a2680933462f8f263cc1eefd8efeed79d06bf930f069';
    private const VECTOR_DATOS = '3f44f64ab5c345958911b434624e3c89b9589bc3200549d43f2a6239c2b0fc0e';

    private function plantilla(): array
    {
        return ['dimensiones' => ['alto_mm' => 86, 'ancho_mm' => 54], 'ajustes' => ['offset_x_mm' => 0.1, 'offset_y_mm' => -1.5, 'escala' => 2.0, 'suma' => 0.1 + 0.2, 'fino' => 12345.678], 'elementos' => [['campo' => 'nombre', 'x' => 3], ['campo' => 'folio', 'x' => 1]]];
    }

    private function datos(): array
    {
        return ['nombre' => 'PERSONA FICTICIA ÑÚÑEZ', 'folio' => 'FOL-0000-00000', 'clase_arma' => "PISTOLA\nCARABINA", 'texto_fijo' => '', 'observacion' => null, 'ruta' => 'a/b'];
    }

    private function manifiesto(string $plantillaBytes, string $datosBytes): array
    {
        return ['manifiesto_version' => 1, 'plantilla_config_sha256' => CanonicalJson::hash($plantillaBytes), 'datos_variables_sha256' => CanonicalJson::hash($datosBytes),
            'biometricos' => ['foto' => str_repeat('1', 64), 'firma' => str_repeat('2', 64), 'pulgar_derecho' => str_repeat('3', 64), 'pulgar_izquierdo' => str_repeat('4', 64)]];
    }

    public function test_reproduce_exactamente_el_vector_de_referencia(): void
    {
        $plantilla = CanonicalJson::encode($this->plantilla());
        $datos = CanonicalJson::encode($this->datos());
        $manifiesto = CanonicalJson::encode($this->manifiesto($plantilla, $datos));

        $this->assertSame(self::VECTOR_PLANTILLA, CanonicalJson::hash($plantilla));
        $this->assertSame(self::VECTOR_DATOS, CanonicalJson::hash($datos));
        $this->assertSame(self::VECTOR_MANIFIESTO, CanonicalJson::hash($manifiesto));
        $this->assertSame('{"clase_arma":"PISTOLA\nCARABINA","folio":"FOL-0000-00000","nombre":"PERSONA FICTICIA ÑÚÑEZ","observacion":null,"ruta":"a/b","texto_fijo":""}', $datos);
    }

    public function test_orden_de_claves_no_altera_el_hash(): void
    {
        $this->assertSame(CanonicalJson::encode($this->datos()), CanonicalJson::encode(array_reverse($this->datos(), true)));
    }

    public function test_listas_conservan_su_orden(): void
    {
        $this->assertNotSame(CanonicalJson::encode(['a' => [1, 2]]), CanonicalJson::encode(['a' => [2, 1]]));
    }

    public function test_unicode_sin_escapes_ni_normalizacion(): void
    {
        $bytes = CanonicalJson::encode($this->datos());
        $this->assertStringContainsString('ÑÚÑEZ', $bytes);
        $this->assertStringNotContainsString('\u', $bytes);

        $nfd = $this->datos();
        $nfd['nombre'] = "PERSONA FICTICIA N\u{0303}U\u{0301}N\u{0303}EZ";
        $this->assertNotSame($bytes, CanonicalJson::encode($nfd));
    }

    public function test_null_cadena_vacia_y_clave_ausente_son_distintos(): void
    {
        $this->assertNotSame(CanonicalJson::encode(['a' => null]), CanonicalJson::encode([]));
        $this->assertNotSame(CanonicalJson::encode(['a' => '']), CanonicalJson::encode(['a' => null]));
    }

    public function test_decimales_admitidos_son_estables(): void
    {
        $bytes = CanonicalJson::encode($this->plantilla());
        $this->assertStringContainsString('"escala":2.0', $bytes);
        $this->assertStringContainsString('"offset_x_mm":0.1', $bytes);
        $this->assertStringContainsString('0.30000000000000004', $bytes);
        // Los bytes guardados son la referencia: decodificar y recodificar los reproduce exactamente.
        $this->assertSame($bytes, CanonicalJson::encode(json_decode($bytes, true)));
    }

    public function test_hash_de_los_bytes_originales(): void
    {
        $bytes = CanonicalJson::encode($this->datos());
        $this->assertSame(hash('sha256', $bytes), CanonicalJson::hash($bytes));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', CanonicalJson::hash($bytes));
    }

    public function test_rechaza_claves_no_ascii(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CanonicalJson::encode(['clave_ñ' => 1]);
    }

    protected function tearDown(): void
    {
        CanonicalJson::$establecerDirectiva = null;
        ini_set('serialize_precision', '-1');
        parent::tearDown();
    }

    public function test_el_vector_es_identico_con_serialize_precision_inicial_17_o_menos_1_y_se_restaura(): void
    {
        foreach (['17', '-1'] as $inicial) {
            ini_set('serialize_precision', $inicial);
            $plantilla = CanonicalJson::encode($this->plantilla());
            $datos = CanonicalJson::encode($this->datos());
            $manifiesto = CanonicalJson::encode($this->manifiesto($plantilla, $datos));

            $this->assertSame(self::VECTOR_PLANTILLA, CanonicalJson::hash($plantilla), "serialize_precision inicial {$inicial}");
            $this->assertSame(self::VECTOR_MANIFIESTO, CanonicalJson::hash($manifiesto), "serialize_precision inicial {$inicial}");
            $this->assertSame($inicial, ini_get('serialize_precision'), 'La directiva se restaura tras una ejecución exitosa.');
        }
    }

    public function test_la_directiva_se_restaura_si_la_serializacion_falla(): void
    {
        ini_set('serialize_precision', '17');
        try {
            CanonicalJson::encode(['clave_ñ' => 1.5]);
            $this->fail('Debió rechazar la clave no ASCII.');
        } catch (InvalidArgumentException) {
            $this->assertSame('17', ini_get('serialize_precision'));
        }
    }

    public function test_falla_explicitamente_si_no_puede_fijar_la_directiva(): void
    {
        ini_set('serialize_precision', '17');
        CanonicalJson::$establecerDirectiva = static fn (): bool => false;

        try {
            CanonicalJson::encode($this->datos());
            $this->fail('Debió fallar en lugar de producir un hash no canónico.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('serialize_precision', $e->getMessage());
            $this->assertSame('17', ini_get('serialize_precision'));
        }
    }

    public function test_rechaza_decimales_con_exponente(): void
    {
        foreach ([1.0E-7, 1.0E+21, INF] as $valor) {
            try {
                CanonicalJson::encode(['x' => $valor]);
                $this->fail('Debió rechazar un decimal fuera de rango.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
