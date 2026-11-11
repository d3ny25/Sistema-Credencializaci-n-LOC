<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BiometricoCategoria;
use App\Enums\BiometricoTipoIdentificacion;
use App\Support\BiometricFilenameParser;
use PHPUnit\Framework\TestCase;

class BiometricFilenameParserTest extends TestCase
{
    protected BiometricFilenameParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new BiometricFilenameParser();
    }

    // =========================================================================
    // 1. PATRONES ESTÁNDAR NACIONAL DE CUIP
    // =========================================================================

    public function test_parsea_cuip_estandar_con_categoria_en_ruta(): void
    {
        $path = 'ESTATALES/FOTOS/AAAA690916H15136329.JPG';
        $result = $this->parser->parse($path);

        $this->assertTrue($result->esValido);
        $this->assertTrue($result->esCuipValido);
        $this->assertTrue($result->esExtensionAdmitida);
        $this->assertSame('AAAA690916H15136329', $result->cuipDetectado);
        $this->assertSame('AAAA690916H15136329', $result->cuipNormalizado);
        $this->assertSame(BiometricoCategoria::FOTO, $result->categoria);
        $this->assertSame(BiometricoTipoIdentificacion::ESTANDAR, $result->tipoIdentificacion);
        $this->assertSame('jpg', $result->extension);
        $this->assertSame('AAAA690916H15136329.JPG', $result->nombreArchivo);
        $this->assertTrue($result->esAptoParaIndexacion());
    }

    public function test_parsea_las_cuatro_categorias_desde_carpetas_canonicas(): void
    {
        $casos = [
            'ESTATALES/FOTOS/MARJ880204H154726065.jpg' => BiometricoCategoria::FOTO,
            'ESTATALES/Firmas/MARJ880204H154726065.png' => BiometricoCategoria::FIRMA,
            'PREVENCION/FIRMAS/MARJ880204H154726065.png' => BiometricoCategoria::FIRMA,
            'ESTATALES/PULGAR DERECHO/MARJ880204H154726065.jpg' => BiometricoCategoria::PULGAR_DERECHO,
            'PREVENCION/PULGAR IZQUIERDO/MARJ880204H154726065.jpg' => BiometricoCategoria::PULGAR_IZQUIERDO,
        ];

        foreach ($casos as $ruta => $categoriaEsperada) {
            $res = $this->parser->parse($ruta);
            $this->assertTrue($res->esValido, "Fallo de validez en ruta: {$ruta}");
            $this->assertSame($categoriaEsperada, $res->categoria);
            $this->assertSame('MARJ880204H154726065', $res->cuipNormalizado);
        }
    }

    public function test_admite_categoria_provista_exploratoriamente_por_contexto(): void
    {
        $filename = 'AAAA690916H15136329.wsq';
        $res = $this->parser->parse($filename, BiometricoCategoria::PULGAR_DERECHO);

        $this->assertTrue($res->esValido);
        $this->assertSame(BiometricoCategoria::PULGAR_DERECHO, $res->categoria);
        $this->assertSame('wsq', $res->extension);
    }

    public function test_infiere_categoria_desde_sufijo_en_nombre_si_la_ruta_no_indica(): void
    {
        $casos = [
            'AAAA690916H15136329_FOTO.jpg' => BiometricoCategoria::FOTO,
            'AAAA690916H15136329_FIRMA.png' => BiometricoCategoria::FIRMA,
            'AAAA690916H15136329_PD.jpg' => BiometricoCategoria::PULGAR_DERECHO,
            'AAAA690916H15136329_PI.jpg' => BiometricoCategoria::PULGAR_IZQUIERDO,
        ];

        foreach ($casos as $archivo => $catEsperada) {
            $res = $this->parser->parse($archivo);
            $this->assertSame($catEsperada, $res->categoria, "Fallo infiriendo categoría en {$archivo}");
        }
    }

    // =========================================================================
    // 2. PATRÓN CORTO RFC (10 CARACTERES)
    // =========================================================================

    public function test_detecta_patron_corto_rfc_y_lo_clasifica_como_incidencia_homologable(): void
    {
        $path = 'ESTATALES/Firmas/AAAA820529.PNG';
        $res = $this->parser->parse($path);

        $this->assertFalse($res->esValido);
        $this->assertFalse($res->esCuipValido);
        $this->assertSame(BiometricoTipoIdentificacion::CORTO_RFC, $res->tipoIdentificacion);
        $this->assertSame('AAAA820529', $res->cuipDetectado);
        $this->assertNull($res->cuipNormalizado);
        $this->assertSame(BiometricoCategoria::FIRMA, $res->categoria);
        $this->assertSame('PATRON_CUIP_CORTO_RFC', $res->codigoIncidencia);
        $this->assertTrue($res->tieneIncidencia());
        $this->assertFalse($res->esAptoParaIndexacion());
    }

    // =========================================================================
    // 3. ARCHIVOS HUÉRFANOS GUID DE HARDWARE
    // =========================================================================

    public function test_detecta_archivos_con_guid_de_hardware(): void
    {
        $nombresGuid = [
            '{00987e0b-071b-4325-9d94-cb0a0610037f}.jpg',
            '00987e0b-071b-4325-9d94-cb0a0610037f.jpg',
            '{F47AC10B-58CC-4372-A567-0E02B2C3D479}.PNG',
        ];

        foreach ($nombresGuid as $guidFile) {
            $res = $this->parser->parse("ESTATALES/PULGAR DERECHO/{$guidFile}");

            $this->assertFalse($res->esValido);
            $this->assertFalse($res->esCuipValido);
            $this->assertSame(BiometricoTipoIdentificacion::GUID_HARDWARE, $res->tipoIdentificacion);
            $this->assertNull($res->cuipDetectado);
            $this->assertSame('NOMBRE_GUID_SIN_CUIP', $res->codigoIncidencia);
        }
    }

    // =========================================================================
    // 4. ARCHIVOS OCULTOS SIN NOMBRE (DOT-FILES)
    // =========================================================================

    public function test_detecta_archivos_ocultos_sin_nombre(): void
    {
        $resJpg = $this->parser->parse('PREVENCION/FOTOS/.jpg');
        $this->assertFalse($resJpg->esValido);
        $this->assertSame(BiometricoTipoIdentificacion::SIN_NOMBRE, $resJpg->tipoIdentificacion);
        $this->assertSame('NOMBRE_ANOMALO', $resJpg->codigoIncidencia);

        $resPng = $this->parser->parse('PREVENCION/FIRMAS/.png');
        $this->assertFalse($resPng->esValido);
        $this->assertSame(BiometricoTipoIdentificacion::SIN_NOMBRE, $resPng->tipoIdentificacion);
    }

    // =========================================================================
    // 5. ARCHIVOS DE SISTEMA O TEXTO
    // =========================================================================

    public function test_detecta_archivos_de_sistema_como_desktop_ini_y_listados(): void
    {
        $resDesktop = $this->parser->parse('ESTATALES/FOTOS/desktop.ini');
        $this->assertFalse($resDesktop->esValido);
        $this->assertSame(BiometricoTipoIdentificacion::SISTEMA, $resDesktop->tipoIdentificacion);
        $this->assertSame('ARCHIVO_SISTEMA', $resDesktop->codigoIncidencia);

        $resTxt = $this->parser->parse('ESTATALES/PULGAR DERECHO/LISTPD.TXT');
        $this->assertFalse($resTxt->esValido);
        $this->assertSame(BiometricoTipoIdentificacion::SISTEMA, $resTxt->tipoIdentificacion);
        $this->assertSame('NO_IMAGEN', $resTxt->codigoIncidencia);
    }

    // =========================================================================
    // 6. PATRONES NO ESTÁNDAR, AMBIGUOS O CORRUPTOS
    // =========================================================================

    public function test_detecta_nombres_con_longitud_o_caracteres_irregulares(): void
    {
        $irregulares = [
            'MARJ880204_COPIA.jpg',
            'MARJ880204H154726065 (1).jpg',
            '12345.jpg',
            'FOTO_POLICIA.jpg',
            'INVALID@CUIP!1234567.jpg',
        ];

        foreach ($irregulares as $irreg) {
            $res = $this->parser->parse("ESTATALES/FOTOS/{$irreg}");
            $this->assertFalse($res->esValido, "El archivo irregular debió ser inválido: {$irreg}");
            $this->assertSame(BiometricoTipoIdentificacion::NO_ESTANDAR, $res->tipoIdentificacion);
            $this->assertSame('PATRON_CUIP_NO_ESTANDAR', $res->codigoIncidencia);
        }
    }

    public function test_detecta_extensiones_no_admitidas(): void
    {
        $resPdf = $this->parser->parse('ESTATALES/FOTOS/AAAA690916H15136329.pdf');
        $this->assertFalse($resPdf->esValido);
        $this->assertFalse($resPdf->esExtensionAdmitida);
        $this->assertSame('EXTENSION_NO_ADMITIDA', $resPdf->codigoIncidencia);
    }

    public function test_detecta_categoria_desconocida_si_no_se_provee_en_ruta(): void
    {
        $res = $this->parser->parse('CARPETA_DESCONOCIDA/AAAA690916H15136329.jpg');
        $this->assertFalse($res->esValido);
        $this->assertNull($res->categoria);
        $this->assertSame('CATEGORIA_DESCONOCIDA', $res->codigoIncidencia);
    }
}

