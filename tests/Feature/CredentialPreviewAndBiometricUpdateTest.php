<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoCategoria;
use App\Enums\BiometricoEstadoArchivo;
use App\Enums\BiometricoEstadoRevision;
use App\Enums\BiometricoTipoIdentificacion;
use App\Models\BiometricoArchivo;
use App\Models\BiometricoArchivoVersion;
use App\Models\BiometricoAuditoria;
use App\Models\BiometricoExpediente;
use App\Models\CredentialTemplate;
use App\Models\LocCatalogoOrganizacional;
use App\Models\LocCatalogoVersion;
use App\Models\LocPersona;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CredentialPreviewAndBiometricUpdateTest extends TestCase
{
    use RefreshDatabase;

    private User $authorizedUser;
    private User $unauthorizedUser;
    private SolicitudElemento $elemento;
    private SolicitudCredencial $solicitud;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('biometricos');
        Storage::fake('biometricos_ingesta');

        $this->authorizedUser = User::factory()->create([
            'role' => 'impresion',
        ]);

        $this->unauthorizedUser = User::factory()->create([
            'role' => 'consulta_general',
        ]);

        $this->solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TJ-TEST-PREVIEW-01',
            'consecutivo_tarjeta' => 1,
            'numero_oficio' => 'OF-TEST-001',
            'fecha_recepcion' => now(),
            'estado_general' => 'recibida',
            'area_solicitante' => 'DIRECCION DE SEGURIDAD PUBLICA',
            'total_elementos' => 1,
            'total_aptos' => 1,
        ]);

        $persona = LocPersona::create([
            'cuip' => 'TESTCUIP9999999999',
            'nombre' => 'POLICIA DE PRUEBA OFICIAL',
            'categoria' => 'OFICIAL DE POLICIA',
            'subdireccion' => 'SUBDIRECCION NORTE',
            'municipio' => 'TOLUCA',
            'activo' => true,
        ]);

        $this->elemento = SolicitudElemento::create([
            'solicitud_id' => $this->solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'TESTCUIP9999999999',
            'persona_id' => $persona->id,
            'resultado_prefiltro' => 'Apto',
            'estado_tramite' => 'pendiente_biometrico',
            'ambito_biometrico' => BiometricoAmbito::ESTATALES,
            'estado_clasificacion_ambito' => \App\Enums\BiometricoClasificacionEstado::VALIDADA,
            'datos_solicitados' => [
                'nombre' => 'POLICIA DE PRUEBA OFICIAL',
                'categoria' => 'OFICIAL DE POLICIA',
                'municipio' => 'TOLUCA',
            ],
            'datos_originales_fila' => [
                'NOMBRE' => 'POLICIA DE PRUEBA OFICIAL',
            ],
        ]);
    }

    public function test_previsualizacion_requiere_autenticacion(): void
    {
        $response = $this->getJson(route('impresion.elemento.previsualizacion', $this->elemento));
        $response->assertUnauthorized();
    }

    public function test_previsualizacion_bloquea_usuario_no_autorizado(): void
    {
        $this->actingAs($this->unauthorizedUser);

        $response = $this->getJson(route('impresion.elemento.previsualizacion', $this->elemento));
        $response->assertForbidden();
    }

    public function test_previsualizacion_retorna_payload_completo_para_usuario_autorizado(): void
    {
        $this->actingAs($this->authorizedUser);

        $response = $this->getJson(route('impresion.elemento.previsualizacion', $this->elemento));

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'elemento' => [
                'id',
                'cuip',
                'nombre',
                'categoria',
                'tarjeta',
                'oficio',
                'folio',
                'ambito',
                'estado_tramite',
                'puede_imprimir',
            ],
            'template' => [
                'version',
                'width_mm',
                'height_mm',
                'config',
                'front_bg',
                'rear_bg',
            ],
            'campos_valores' => [
                'folio',
                'numero_tarjeta',
                'cuip',
                'nombre',
                'categoria',
            ],
            'biometricos' => [
                'foto' => ['label', 'existe', 'url', 'sha256'],
                'firma' => ['label', 'existe', 'url', 'sha256'],
                'pulgar_derecho' => ['label', 'existe', 'url', 'sha256'],
                'pulgar_izquierdo' => ['label', 'existe', 'url', 'sha256'],
            ],
            'semaforo' => [
                'completos',
                'total',
                'es_completo',
                'faltantes',
            ],
        ]);

        $this->assertSame('TESTCUIP9999999999', $response->json('elemento.cuip'));
        $this->assertSame('POLICIA DE PRUEBA OFICIAL', $response->json('elemento.nombre'));
        $this->assertFalse($response->json('semaforo.es_completo'));

        // Verifica que se haya registrado la auditoría inmutable
        $this->assertDatabaseHas('biometrico_auditorias', [
            'evento' => 'vista_previa_credencial',
            'user_id' => $this->authorizedUser->id,
            'cuip_snapshot' => 'TESTCUIP9999999999',
        ]);
    }

    private function createValidJpeg(string $name = 'test.jpg'): UploadedFile
    {
        $content = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAIBAQEBAQIBAQECAgICAgQDAgICAgUEBAMEBgUGBgYFBgYGBwkIBgcJBwYGCAsICQoKCgoKBggLDAsKDAkKCgr/2wBDAQICAgICAgUDAwUKBwYHCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgr/wAARCABkAGQDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD9/KKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKqabDrsV5qD6vqVpPbyXatpcVtZNE9vB5MYMcrNI4mcyiZw6rGAjomwlDJImyoxTTbdreuuuy/PWysu9k7dFFFMkKKKKACiiigAooooAKKKKAM6XRL6TxTbeIF8R3K28FlcQPpQggMUrytbsspcx+arRiFwqq4VvtDl1crEY5ptNvJddttXTX7uO3gtJ4ZdLRIfIuHdomWZyYzKHjEbqoR1QieTerkRmO3RWFLDUaDm4X958z1b1sl1eiskrLS3Q19tPTbRNbLZ38tXq7N6rSz0VimiKISmcRqHZQrOByQM4GfQZP5mnUVrKEJNNq9tV5O1tPk2vRmQUUUVQBRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQAUUUUAFFFFABRRRQB//Z');
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function createValidBmp(string $name = 'huella.bmp'): UploadedFile
    {
        $w = 60;
        $h = 60;
        $rowSize = (int) floor((24 * $w + 31) / 32) * 4;
        $pixelArraySize = $rowSize * $h;
        $fileSize = 54 + $pixelArraySize;

        $header = pack('A2VvvV', 'BM', $fileSize, 0, 0, 54);
        $dib = pack('VVVvvVVVVVV', 40, $w, $h, 1, 24, 0, $pixelArraySize, 2835, 2835, 0, 0);
        $pixels = str_repeat("\x80", $pixelArraySize);
        $bmp = $header . $dib . $pixels;

        return UploadedFile::fake()->createWithContent($name, $bmp);
    }

    public function test_actualizacion_biometrica_acepta_formato_bmp_de_crossmatch(): void
    {
        $this->actingAs($this->authorizedUser);

        $fakeBmp = $this->createValidBmp('huella_pulgar_crossmatch.bmp');

        $response = $this->postJson(route('impresion.elemento.actualizar-biometrico', $this->elemento), [
            'tipo_biometrico' => 'pulgar_derecho',
            'archivo' => $fakeBmp,
            'motivo_cambio' => 'Captura dactilar oficial desde lector biométrico Crossmatch.',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'categoria' => 'pulgar_derecho',
        ]);

        $archivo = BiometricoArchivo::where('cuip_normalizado', 'TESTCUIP9999999999')
            ->where('categoria', 'pulgar_derecho')
            ->first();

        $this->assertNotNull($archivo);
        $this->assertSame('bmp', $archivo->extension);
        $this->assertSame('image/bmp', $archivo->mime_type);
        $this->assertTrue($archivo->es_vigente);
    }

    public function test_actualizacion_biometrica_valida_archivo_y_registra_version_y_auditoria(): void
    {
        $this->actingAs($this->authorizedUser);

        // Crear una imagen JPEG válida sin requerir ext-gd
        $fakeImage = $this->createValidJpeg('fotografia_oficial.jpg');

        $response = $this->postJson(route('impresion.elemento.actualizar-biometrico', $this->elemento), [
            'tipo_biometrico' => 'foto',
            'archivo' => $fakeImage,
            'motivo_cambio' => 'Actualización institucional por renovación de credencial oficial.',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'categoria' => 'foto',
        ]);

        // Verificar que el archivo se creó en la BD
        $archivo = BiometricoArchivo::where('cuip_normalizado', 'TESTCUIP9999999999')
            ->where('categoria', 'foto')
            ->first();

        $this->assertNotNull($archivo);
        $this->assertTrue($archivo->es_vigente);
        $this->assertSame(1, $archivo->version_secuencia);

        // Verificar que el expediente se creó y vinculó la foto
        $this->elemento->refresh();
        $this->assertNotNull($this->elemento->biometrico_expediente_id);
        $expediente = $this->elemento->expedienteBiometrico;
        $this->assertSame($archivo->id, $expediente->foto_archivo_id);

        // Verificar registro de auditoría inmutable
        $this->assertDatabaseHas('biometrico_auditorias', [
            'evento' => 'actualizacion_biometrica',
            'user_id' => $this->authorizedUser->id,
            'biometrico_archivo_id' => $archivo->id,
        ]);

        // SEGUNDA ACTUALIZACIÓN (Reemplazo con versionado):
        $fakeImage2 = $this->createValidJpeg('foto_corregida.jpg');
        $response2 = $this->postJson(route('impresion.elemento.actualizar-biometrico', $this->elemento), [
            'tipo_biometrico' => 'foto',
            'archivo' => $fakeImage2,
            'motivo_cambio' => 'Corrección de iluminación pericial en captura facial.',
        ]);

        $response2->assertOk();
        $archivo->refresh();
        $this->assertSame(2, $archivo->version_secuencia);

        // Verificar que se registró la versión histórica en biometrico_archivo_versiones
        $this->assertDatabaseHas('biometrico_archivo_versiones', [
            'biometrico_archivo_id' => $archivo->id,
            'version_secuencia' => 2,
            'observaciones' => 'Corrección de iluminación pericial en captura facial.',
        ]);
    }

    public function test_actualizacion_biometrica_rechaza_archivos_invalidos(): void
    {
        $this->actingAs($this->authorizedUser);

        // Archivo que excede tamaño (3MB)
        $bigFile = UploadedFile::fake()->create('foto_pesada.jpg', 3500, 'image/jpeg');

        $response = $this->postJson(route('impresion.elemento.actualizar-biometrico', $this->elemento), [
            'tipo_biometrico' => 'foto',
            'archivo' => $bigFile,
            'motivo_cambio' => 'Actualización de prueba',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['archivo']);

        // Motivo muy corto
        $smallFile = $this->createValidJpeg('foto.jpg');
        $response2 = $this->postJson(route('impresion.elemento.actualizar-biometrico', $this->elemento), [
            'tipo_biometrico' => 'foto',
            'archivo' => $smallFile,
            'motivo_cambio' => 'abc',
        ]);

        $response2->assertUnprocessable();
        $response2->assertJsonValidationErrors(['motivo_cambio']);
    }

    public function test_streaming_biometrico_entrega_archivo_con_cabeceras_anti_cache(): void
    {
        $this->actingAs($this->authorizedUser);

        // Crear archivo de prueba en disco biometricos_ingesta
        $fakeImage = $this->createValidJpeg('foto_titular.jpg');
        $hash = hash_file('sha256', $fakeImage->getRealPath());
        $subDir = 'INGESTA/ESTATALES/FOTO';
        $filename = 'TESTCUIP9999999999_foto_12345.jpg';
        $relPath = "{$subDir}/{$filename}";
        Storage::disk('biometricos_ingesta')->putFileAs($subDir, $fakeImage, $filename);

        $archivo = BiometricoArchivo::create([
            'ambito' => BiometricoAmbito::ESTATALES,
            'categoria' => BiometricoCategoria::FOTO,
            'ruta_relativa' => $relPath,
            'nombre_archivo' => $filename,
            'extension' => 'jpg',
            'tamano_bytes' => $fakeImage->getSize(),
            'formato_real' => 'jpg',
            'mime_type' => 'image/jpeg',
            'sha256' => $hash,
            'cuip_detectado' => 'TESTCUIP9999999999',
            'cuip_normalizado' => 'TESTCUIP9999999999',
            'tipo_identificacion' => BiometricoTipoIdentificacion::ESTANDAR,
            'estado_archivo' => BiometricoEstadoArchivo::VALIDO,
            'es_vigente' => true,
            'version_secuencia' => 1,
        ]);

        $expediente = BiometricoExpediente::create([
            'cuip' => 'TESTCUIP9999999999',
            'ambito' => BiometricoAmbito::ESTATALES,
            'foto_archivo_id' => $archivo->id,
            'total_biometricos' => 1,
            'es_completo' => false,
            'faltantes' => ['firma', 'pulgar_derecho', 'pulgar_izquierdo'],
            'estado_revision' => BiometricoEstadoRevision::SIN_EVALUAR,
        ]);

        $this->elemento->biometrico_expediente_id = $expediente->id;
        $this->elemento->save();

        $response = $this->get(route('impresion.elemento.biometrico.stream', [
            'elemento' => $this->elemento->id,
            'categoria' => 'foto',
        ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
        $response->assertHeader('Pragma', 'no-cache');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
