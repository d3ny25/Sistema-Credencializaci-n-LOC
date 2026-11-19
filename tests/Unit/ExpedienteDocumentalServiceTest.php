<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\CategoriaDocumentoEntrega;
use App\Services\ExpedienteDocumentalService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Reglas puras del expediente documental: catálogo, saneamiento de nombres e inspección de contenido. */
class ExpedienteDocumentalServiceTest extends TestCase
{
    public function test_catalogo_centralizado_y_descripcion_obligatoria_solo_en_otros(): void
    {
        $this->assertSame(['oficio_solicitud' => 'Oficio de solicitud', 'confirmacion_recepcion' => 'Confirmación de recepción', 'otros' => 'Otros documentos'], CategoriaDocumentoEntrega::opciones());
        $this->assertSame([false, false, true], array_map(fn ($c) => $c->exigeDescripcion(), CategoriaDocumentoEntrega::cases()));
    }

    public function test_el_nombre_original_se_sanea_sin_rutas_ni_caracteres_de_control(): void
    {
        $servicio = new ExpedienteDocumentalService;
        $this->assertSame('passwd.pdf', $servicio->nombreSeguro('../../etc/passwd.pdf', 'pdf'));
        $this->assertSame('evil.pdf', $servicio->nombreSeguro('C:\\temp\\evil.pdf', 'pdf'));
        $this->assertSame('Oficio 12 final.pdf', $servicio->nombreSeguro("Oficio\t12 \"final\x00\".pdf", 'pdf'));
        $this->assertSame('documento.png', $servicio->nombreSeguro('...', 'png'));
        $this->assertSame(255, mb_strlen($servicio->nombreSeguro(str_repeat('á', 400).'.pdf', 'pdf')));
    }

    public function test_inspeccion_exige_coincidencia_entre_extension_y_contenido(): void
    {
        $servicio = new ExpedienteDocumentalService;
        $this->assertSame(['pdf', 'application/pdf'], $servicio->inspeccionar(UploadedFile::fake()->createWithContent('a.PDF', "%PDF-1.4\n%%EOF\n")));

        foreach (['a.pdf' => '<html></html>', 'a.png' => "%PDF-1.4\n%%EOF", 'a.svg' => '<svg/>', 'a.docx' => "%PDF-1.4\n%%EOF"] as $nombre => $contenido) {
            try {
                $servicio->inspeccionar(UploadedFile::fake()->createWithContent($nombre, $contenido));
                $this->fail("Se aceptó {$nombre}");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('no cumple las validaciones', $e->errors()['archivo'][0]);
            }
        }
    }
}
