<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CategoriaDocumentoEntrega;
use App\Models\CredencialDocumento;
use App\Models\CredencialEntrega;
use App\Models\CredencialImpresion;
use App\Models\SolicitudCredencial;
use App\Models\User;
use App\Services\ExpedienteDocumentalService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\PreparaImpresionReal;
use Tests\TestCase;
use ZipArchive;

/**
 * Entrega y Seguimiento, Iteración 1.4: expediente documental. Archivos sintéticos generados en la prueba y disco
 * documentos_entrega simulado; ningún documento institucional.
 */
class ExpedienteDocumentalTest extends TestCase
{
    use PreparaImpresionReal;
    use RefreshDatabase;

    private User $gestor;

    private User $consulta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDiscosEvidencia();
        Storage::fake(ExpedienteDocumentalService::DISCO);
        $this->gestor = User::factory()->create(['role' => 'superadmin']);
        $this->consulta = User::factory()->create(['role' => 'impresion']);
    }

    private function pdf(string $nombre = 'oficio.pdf', string $texto = 'sintetico'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, "%PDF-1.4\n% {$texto}\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    private function docx(string $nombre = 'anexo.docx', bool $conMacros = false): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>');
        if ($conMacros) {
            $zip->addFromString('word/vbaProject.bin', 'macro');
        }
        $zip->close();

        return UploadedFile::fake()->createWithContent($nombre, (string) file_get_contents($ruta));
    }

    private function subir(SolicitudCredencial $tarjeta, UploadedFile $archivo, string $categoria = 'oficio_solicitud', ?string $descripcion = null, ?User $usuario = null)
    {
        return $this->actingAs($usuario ?? $this->gestor)->post(route('entregas.documento.store', $tarjeta), array_filter([
            'categoria' => $categoria, 'descripcion' => $descripcion, 'archivo' => $archivo, 'confirmacion' => '1',
        ], static fn ($v) => $v !== null));
    }

    private function seccion(SolicitudCredencial $tarjeta, array $query = [], ?User $usuario = null)
    {
        return $this->actingAs($usuario ?? $this->consulta)->get(route('entregas.tarjeta', ['solicitud' => $tarjeta->id, 'seccion' => 'expediente-documental', ...$query]));
    }

    private function tarjeta(int $n = 1): SolicitudCredencial
    {
        return $this->elementoImprimible($n, folio: sprintf('FOL-2026-%05d', $n), conBiometria: false)->solicitud;
    }

    // ── Carga ──────────────────────────────────────────────────────────────────────────────────────────────

    public function test_carga_las_tres_categorias_con_metadatos_usuario_y_fecha_del_servidor(): void
    {
        $tarjeta = $this->tarjeta();
        $this->travelTo(Carbon::parse('2026-11-21 09:30:00'));

        $this->subir($tarjeta, $this->pdf('Oficio CE-123.pdf'))->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->subir($tarjeta, UploadedFile::fake()->createWithContent('acuse.png', $this->pngSintetico(3)), 'confirmacion_recepcion')->assertSessionHasNoErrors();
        $this->subir($tarjeta, $this->docx('Minuta.docx'), 'otros', 'Minuta de entrega al enlace')->assertSessionHasNoErrors();

        $documentos = CredencialDocumento::orderBy('id')->get();
        $this->assertSame(['oficio_solicitud', 'confirmacion_recepcion', 'otros'], $documentos->map(fn ($d) => $d->categoria->value)->all());
        $oficio = $documentos[0];
        $this->assertSame('Oficio CE-123.pdf', $oficio->nombre_original);
        $this->assertSame(['pdf', 'application/pdf'], [$oficio->extension, $oficio->mime]);
        $this->assertSame(strlen((string) Storage::disk(ExpedienteDocumentalService::DISCO)->get($oficio->ruta)), $oficio->tamano_bytes);
        $this->assertSame($this->gestor->id, $oficio->subido_por_user_id);
        $this->assertTrue($oficio->subido_at->equalTo(Carbon::parse('2026-11-21 09:30:00')));
        $this->assertSame('Minuta de entrega al enlace', $documentos[2]->descripcion);
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $documentos[2]->mime);
        // Ruta interna UUID bajo la tarjeta, nunca el nombre original.
        foreach ($documentos as $documento) {
            $this->assertMatchesRegularExpression('#^'.$tarjeta->id.'/[0-9a-f-]{36}\.(pdf|png|docx)$#', $documento->ruta);
            Storage::disk(ExpedienteDocumentalService::DISCO)->assertExists($documento->ruta);
        }
    }

    public function test_valida_categoria_descripcion_confirmacion_formato_contenido_y_tamano(): void
    {
        $tarjeta = $this->tarjeta();
        $this->subir($tarjeta, $this->pdf(), 'categoria_inventada')->assertSessionHasErrors('categoria');
        $this->subir($tarjeta, $this->pdf(), 'otros')->assertSessionHasErrors('descripcion');
        $this->actingAs($this->gestor)->post(route('entregas.documento.store', $tarjeta), ['categoria' => 'oficio_solicitud', 'archivo' => $this->pdf()])->assertSessionHasErrors('confirmacion');
        foreach ([
            UploadedFile::fake()->createWithContent('script.php', '<?php echo 1;'),
            UploadedFile::fake()->createWithContent('pagina.html', '<html><script>alert(1)</script></html>'),
            UploadedFile::fake()->createWithContent('imagen.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('codigo.js', 'alert(1)'),
            UploadedFile::fake()->createWithContent('programa.exe', "MZ\x90\x00binario"),
            // Extensión permitida con contenido que no corresponde.
            UploadedFile::fake()->createWithContent('disfrazado.pdf', '<html><script>alert(1)</script></html>'),
            UploadedFile::fake()->createWithContent('falso.png', "%PDF-1.4\n%%EOF"),
            $this->docx('macros.docx', conMacros: true),
        ] as $archivo) {
            $this->subir($tarjeta, $archivo)->assertSessionHasErrors('archivo');
            $this->assertStringContainsString('no cumple las validaciones', session('errors')->first('archivo'), $archivo->getClientOriginalName());
        }

        $grande = UploadedFile::fake()->createWithContent('grande.pdf', "%PDF-1.4\n".str_repeat('0', (int) config('credencializacion.documentos.max_kb') * 1024 + 10));
        $this->subir($tarjeta, $grande)->assertSessionHasErrors('archivo');
        $this->assertStringContainsString('tamaño máximo', implode(' ', session('errors')->get('archivo')));

        $this->assertSame(0, CredencialDocumento::count());
        $this->assertSame([], Storage::disk(ExpedienteDocumentalService::DISCO)->allFiles());
    }

    public function test_la_tarjeta_sale_de_la_ruta_y_un_identificador_manipulado_no_cambia_el_expediente(): void
    {
        [$a, $b] = [$this->tarjeta(1), $this->tarjeta(2)];

        $this->actingAs($this->gestor)->post(route('entregas.documento.store', $a), [
            'categoria' => 'oficio_solicitud', 'archivo' => $this->pdf(), 'confirmacion' => '1', 'solicitud_id' => $b->id, 'subido_por_user_id' => $this->consulta->id,
        ])->assertSessionHasNoErrors();

        $documento = CredencialDocumento::sole();
        $this->assertSame([$a->id, $this->gestor->id], [$documento->solicitud_id, $documento->subido_por_user_id]);
        $this->seccion($b)->assertViewHas('documentos', fn ($d) => $d->total() === 0)->assertDontSee('data-documento=', false);
        foreach (['ver', 'descargar'] as $modo) {
            $this->actingAs($this->consulta)->get(route('entregas.documento.archivo', [$b, $documento, $modo]))->assertNotFound();
        }
    }

    // ── Consulta y descarga ────────────────────────────────────────────────────────────────────────────────

    public function test_lista_filtra_y_sirve_solo_documentos_del_expediente_con_encabezados_seguros(): void
    {
        [$a, $b] = [$this->tarjeta(1), $this->tarjeta(2)];
        $this->subir($a, $this->pdf('oficio-a.pdf', 'a'));
        $this->subir($a, $this->docx('Minuta.docx'), 'otros', 'Minuta del enlace');
        $this->subir($b, $this->pdf('oficio-b.pdf', 'b'));
        [$pdf, $docx] = CredencialDocumento::where('solicitud_id', $a->id)->orderBy('id')->get()->all();
        $this->seccion($b); // consume el aviso flash de la última carga (menciona oficio-b.pdf)

        $this->seccion($a)->assertOk()
            ->assertViewHas('documentos', fn ($d) => $d->total() === 2)
            ->assertViewHas('conteosDocumentos', fn (array $c) => $c === ['oficio_solicitud' => 1, 'otros' => 1])
            ->assertSee('oficio-a.pdf')->assertSee('Minuta del enlace')->assertDontSee('oficio-b.pdf')
            ->assertDontSee($pdf->ruta)->assertDontSee('documentos_entrega')
            // Quien solo consulta no ve el formulario de carga.
            ->assertDontSee('Incorporar documento');
        $this->seccion($a, ['categoria' => 'otros'])->assertViewHas('documentos', fn ($d) => $d->total() === 1);
        $this->seccion($a, ['q' => 'minuta'])->assertViewHas('documentos', fn ($d) => $d->total() === 1);
        $this->seccion($a, ['q' => 'no-existe'])->assertSee('Ningún documento coincide con la búsqueda.');
        $this->seccion($a, ['categoria' => 'confirmacion_recepcion'])->assertSee('No hay documentos de la categoría seleccionada.');

        $ver = $this->actingAs($this->consulta)->get(route('entregas.documento.archivo', [$a, $pdf, 'ver']))->assertOk();
        $this->assertSame('application/pdf', $ver->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $ver->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $ver->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $ver->headers->get('Content-Security-Policy'));
        $this->assertStringStartsWith('%PDF-', $ver->streamedContent());

        // DOCX no se previsualiza: siempre se descarga con su nombre original.
        $descarga = $this->actingAs($this->consulta)->get(route('entregas.documento.archivo', [$a, $docx, 'ver']))->assertOk();
        $this->assertStringStartsWith('attachment', (string) $descarga->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Minuta.docx', (string) $descarga->headers->get('Content-Disposition'));
    }

    public function test_sin_permiso_no_se_consulta_descarga_ni_carga(): void
    {
        $tarjeta = $this->tarjeta();
        $this->subir($tarjeta, $this->pdf());
        $documento = CredencialDocumento::sole();
        $archivo = route('entregas.documento.archivo', [$tarjeta, $documento, 'descargar']);

        $sinPermiso = User::factory()->create(['role' => 'consulta_general']);
        $this->seccion($tarjeta, usuario: $sinPermiso)->assertForbidden();
        $this->actingAs($sinPermiso)->get($archivo)->assertForbidden();
        foreach ([$sinPermiso, $this->consulta, User::factory()->create(['role' => 'credencializador'])] as $usuario) {
            $this->subir($tarjeta, $this->pdf('otro.pdf', 'otro'), usuario: $usuario)->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->get($archivo)->assertRedirect(route('login'));
        $this->post(route('entregas.documento.store', $tarjeta), ['categoria' => 'oficio_solicitud', 'archivo' => $this->pdf('x.pdf', 'x'), 'confirmacion' => '1'])->assertRedirect(route('login'));
        $this->assertSame(1, CredencialDocumento::count());
    }

    public function test_almacenamiento_privado_y_rutas_manipuladas_no_sirven_archivos(): void
    {
        $disco = config('filesystems.disks.'.ExpedienteDocumentalService::DISCO);
        $this->assertFalse($disco['serve']);
        $this->assertSame(storage_path('app/documentos_entrega'), $disco['root']);
        $this->assertStringStartsNotWith(public_path(), $disco['root']);

        $tarjeta = $this->tarjeta();
        $this->subir($tarjeta, $this->pdf('../../../etc/passwd.pdf'))->assertSessionHasNoErrors();
        $documento = CredencialDocumento::sole();
        $this->assertSame('passwd.pdf', $documento->nombre_original);
        // La ruta firmada /storage del disco "local" no alcanza este disco: sin firma responde 403 y sin archivo 404.
        foreach (['/storage/'.$documento->ruta, '/storage/documentos_entrega/'.$documento->ruta] as $url) {
            $respuesta = $this->actingAs($this->consulta)->get($url);
            $this->assertContains($respuesta->getStatusCode(), [403, 404], $url);
            $this->assertStringNotContainsString('%PDF', (string) $respuesta->getContent());
        }

        // Registros con rutas manipuladas (insertados sin el servicio): nunca se sirven.
        foreach (['../../.env', $tarjeta->id.'/../../.env', '999/'.Str::uuid().'.pdf', $documento->ruta.'x'] as $i => $ruta) {
            DB::table('credencial_documentos')->insert([...collect($documento->getAttributes())->except('id')->all(), 'uuid' => (string) Str::uuid(), 'ruta' => $ruta, 'sha256' => str_repeat((string) $i, 64)]);
            $manipulado = CredencialDocumento::where('ruta', $ruta)->sole();
            $this->actingAs($this->consulta)->get(route('entregas.documento.archivo', [$tarjeta, $manipulado, 'descargar']))->assertNotFound();
        }
        $this->seccion($tarjeta)->assertSee('Archivo no disponible');
        $this->actingAs($this->consulta)->get('/entregas/tarjetas/'.$tarjeta->id.'/documentos/'.$documento->id.'/ejecutar')->assertNotFound();
    }

    // ── Integridad ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_una_carga_fallida_en_la_base_no_deja_archivo_huerfano(): void
    {
        $tarjeta = $this->tarjeta();
        $fantasma = (new User)->forceFill(['id' => 999999]);

        try {
            app(ExpedienteDocumentalService::class)->incorporar($tarjeta, $this->pdf(), CategoriaDocumentoEntrega::OFICIO_SOLICITUD, null, $fantasma);
            $this->fail('El registro debía fallar por la clave foránea del usuario.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('No fue posible registrar el documento', $e->errors()['archivo'][0]);
        }
        $this->assertSame(0, CredencialDocumento::count());
        $this->assertSame([], Storage::disk(ExpedienteDocumentalService::DISCO)->allFiles());
    }

    public function test_una_version_corregida_es_un_documento_nuevo_y_el_mismo_archivo_no_se_duplica(): void
    {
        $tarjeta = $this->tarjeta();
        $this->subir($tarjeta, $this->pdf('acuse.pdf', 'v1'), 'confirmacion_recepcion');
        $this->subir($tarjeta, $this->pdf('acuse.pdf', 'v1'), 'confirmacion_recepcion')->assertSessionHasErrors('archivo');
        $this->assertStringContainsString('ya forma parte del expediente', session('errors')->first('archivo'));
        $this->subir($tarjeta, $this->pdf('acuse-corregido.pdf', 'v2'), 'confirmacion_recepcion', 'Acuse corregido')->assertSessionHasNoErrors();

        [$original, $corregido] = CredencialDocumento::orderBy('id')->get()->all();
        $this->assertSame(['acuse.pdf', 'acuse-corregido.pdf'], [$original->nombre_original, $corregido->nombre_original]);
        Storage::disk(ExpedienteDocumentalService::DISCO)->assertExists([$original->ruta, $corregido->ruta]);
        $this->assertCount(2, Storage::disk(ExpedienteDocumentalService::DISCO)->allFiles());
    }

    public function test_los_documentos_no_se_editan_ni_eliminan_y_las_consultas_no_los_alteran(): void
    {
        $tarjeta = $this->tarjeta();
        $this->subir($tarjeta, $this->pdf());
        $documento = CredencialDocumento::sole();
        $antes = DB::table('credencial_documentos')->get()->toArray();

        $this->seccion($tarjeta)->assertOk();
        $this->actingAs($this->consulta)->get(route('entregas.documento.archivo', [$tarjeta, $documento, 'descargar']))->assertOk();
        $this->assertEquals($antes, DB::table('credencial_documentos')->get()->toArray());

        foreach ([fn () => $documento->update(['descripcion' => 'x']), fn () => $documento->delete()] as $operacion) {
            try {
                $operacion();
                $this->fail('El modelo permitió alterar un documento.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([fn () => DB::table('credencial_documentos')->update(['categoria' => 'otros']), fn () => DB::table('credencial_documentos')->delete()] as $operacion) {
            try {
                $operacion();
                $this->fail('La base permitió alterar un documento.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_la_carga_no_altera_emision_impresion_ni_entregas_y_convive_con_entregas_parciales(): void
    {
        $impresor = User::factory()->create(['role' => 'impresion']);
        $elemento = $this->elementoImprimible(5, folio: 'FOL-2026-00005');
        $this->emitir($impresor, $elemento);
        $tarjeta = $elemento->solicitud;
        $impresion = CredencialImpresion::where('solicitud_elemento_id', $elemento->id)->sole();
        $this->actingAs($this->gestor)->post(route('entregas.entrega.store', $tarjeta), ['receptor_nombre' => 'Enlace sintético', 'impresiones' => [$impresion->id], 'clave_operacion' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $foto = fn () => [
            DB::table('solicitudes')->get()->toArray(), DB::table('solicitud_elementos')->get()->toArray(), DB::table('credenciales_emitidas')->get()->toArray(),
            DB::table('credencial_impresiones')->get()->toArray(), DB::table('credencial_entregas')->get()->toArray(), DB::table('credencial_entrega_detalles')->get()->toArray(),
            DB::table('biometrico_incidencias')->count(),
        ];
        $antes = $foto();

        $this->subir($tarjeta, $this->pdf('acuse.pdf'), 'confirmacion_recepcion')->assertSessionHasNoErrors();

        $this->assertEquals($antes, $foto(), 'Una confirmación de recepción no registra ni altera entregas, emisión o impresión.');
        $this->assertSame(1, CredencialEntrega::count());
        $this->actingAs($this->gestor)->get(route('entregas.tarjeta', ['solicitud' => $tarjeta->id, 'seccion' => 'entregas']))->assertOk()->assertSee('Enlace sintético');
        $this->seccion($tarjeta)->assertViewHas('documentos', fn ($d) => $d->total() === 1);
    }

    public function test_el_oficio_de_credencializacion_solo_es_un_numero_y_se_distingue_del_archivo(): void
    {
        // Credencialización no almacena el archivo del oficio (solo numero_oficio): no hay referencia reutilizable.
        $tarjeta = $this->tarjeta();
        $this->seccion($tarjeta, usuario: $this->gestor)->assertOk()
            ->assertSee('data-oficio-sin-archivo', false)->assertSee($tarjeta->numero_oficio)
            ->assertSee('El expediente de esta tarjeta aún no tiene documentos.')
            ->assertSee('Incorporar documento');

        $this->subir($tarjeta, $this->pdf())->assertSessionHasNoErrors();
        $this->seccion($tarjeta)->assertDontSee('data-oficio-sin-archivo', false);
    }
}
