<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoCategoria;
use App\Enums\BiometricoClasificacionEstado;
use App\Enums\BiometricoEstadoArchivo;
use App\Enums\BiometricoEstadoRevision;
use App\Enums\BiometricoTipoIdentificacion;
use App\Models\BiometricoArchivo;
use App\Models\BiometricoAuditoria;
use App\Models\BiometricoExpediente;
use App\Models\CredencialEmitida;
use App\Models\LocCatalogoOrganizacional;
use App\Models\LocCatalogoVersion;
use App\Models\LocImportBatch;
use App\Models\LocPersona;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Models\User;
use App\Services\BiometricResolverService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class BiometricoEsquemaYModelosTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private LocCatalogoVersion $catalogoVersion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'auditor.loc@edomex.gob.mx',
            'role' => 'superadmin',
        ]);

        $batch = LocImportBatch::create([
            'tipo_fuente' => 'catalogo_organizacional_loc',
            'nombre_archivo' => 'catalogo_test.xlsx',
            'estado' => 'completado',
            'usuario_id' => $this->user->id,
            'fecha_confirmacion' => now(),
            'total_leidas' => 1,
            'total_insertadas' => 1,
        ]);

        $this->catalogoVersion = LocCatalogoVersion::create([
            'import_batch_id' => $batch->id,
            'version_numero' => 1,
            'archivo_nombre' => 'catalogo_test.xlsx',
            'total_registros' => 1,
            'es_vigente' => true,
            'usuario_id' => $this->user->id,
            'fecha_activacion' => now(),
        ]);
    }

    private function createArchivo(array $overrides = []): BiometricoArchivo
    {
        static $counter = 1;
        $counter++;

        return BiometricoArchivo::create(array_merge([
            'ambito' => BiometricoAmbito::ESTATALES,
            'categoria' => BiometricoCategoria::FOTO,
            'ruta_relativa' => "ESTATALES/FOTO/TEST_CUIP_{$counter}.jpg",
            'nombre_archivo' => "TEST_CUIP_{$counter}.jpg",
            'extension' => 'jpg',
            'tamano_bytes' => 102400,
            'formato_real' => 'JPEG',
            'mime_type' => 'image/jpeg',
            'sha256' => hash('sha256', "TEST_FILE_CONTENT_{$counter}"),
            'cuip_detectado' => "TESTCUIP{$counter}",
            'cuip_normalizado' => "TESTCUIP{$counter}",
            'tipo_identificacion' => BiometricoTipoIdentificacion::ESTANDAR,
            'estado_archivo' => BiometricoEstadoArchivo::VALIDO,
            'es_vigente' => true,
            'version_secuencia' => 1,
        ], $overrides));
    }

    private function createSolicitud(array $attributes = []): SolicitudCredencial
    {
        static $solCounter = 1;
        $solCounter++;

        return SolicitudCredencial::create(array_merge([
            'folio' => "SOL-BIO-{$solCounter}",
            'numero_tarjeta' => "NT-BIO-{$solCounter}",
            'numero_oficio' => "OF-BIO-{$solCounter}",
            'tipo_tramite' => 'expedicion',
            'area_solicitante' => 'Comisaría General',
            'area_solicitante_clave' => 'A1',
            'nombre_solicitante' => 'Auditor Técnico',
            'cargo_solicitante' => 'Auditor',
            'total_elementos' => 1,
            'total_validados' => 0,
            'total_rechazados' => 0,
            'estado' => 'registrada',
            'created_by' => $this->user->id,
        ], $attributes));
    }

    // =========================================================================
    // CORRECCIÓN A: CLASIFICACIÓN DETERMINISTA DEL ÁMBITO (CASOS OBLIGATORIOS)
    // =========================================================================

    public function test_clasificacion_ambito_casos_obligatorios(): void
    {
        // 1. D6 activo y perteneciente a Prevención
        $this->assertEquals(
            BiometricoAmbito::PREVENCION,
            BiometricoAmbito::fromClaveCatalogo('D6', 'DIR.GRAL. PREV. Y REI. SOC.', 'DIR. PREV.', true, true)
        );

        // 2. A1 activo y perteneciente a Estatal
        $this->assertEquals(
            BiometricoAmbito::ESTATALES,
            BiometricoAmbito::fromClaveCatalogo('A1', 'DIR. GRAL. SEG. PUB. Y TTO.', 'SUBDIR. POL. ESTATAL', true, true)
        );

        // 3. A302 con palabra Prevención en texto, pero DG estatal
        $this->assertEquals(
            BiometricoAmbito::ESTATALES,
            BiometricoAmbito::fromClaveCatalogo('A302', 'DIR. GRAL. COM. ROB. VEH. Y TTE.', null, true, true)
        );

        // 4. D99 inexistente en catálogo
        $this->assertEquals(
            BiometricoAmbito::NO_RESUELTO,
            BiometricoAmbito::fromClaveCatalogo('D99', null, null, true, true)
        );

        // 5. Clave inactiva en catálogo (activo = false)
        $this->assertEquals(
            BiometricoAmbito::NO_RESUELTO,
            BiometricoAmbito::fromClaveCatalogo('A1', 'DIR. GRAL. SEG. PUB. Y TTO.', null, false, true)
        );

        // 6. Dirección general vacía (NO debe aplicar fallback por regex)
        $this->assertEquals(
            BiometricoAmbito::NO_RESUELTO,
            BiometricoAmbito::fromClaveCatalogo('A1', '', '', true, true)
        );

        // 7. Dirección general desconocida / no en lista blanca
        $this->assertEquals(
            BiometricoAmbito::NO_RESUELTO,
            BiometricoAmbito::fromClaveCatalogo('A1', 'DIRECCION INVENTADA', '', true, true)
        );

        // 8. Catálogo no disponible (null record)
        $this->assertEquals(
            BiometricoAmbito::NO_RESUELTO,
            BiometricoAmbito::fromCatalogoRecord(null)
        );

        // 9. Versión del catálogo no vigente (es_vigente = false)
        $this->assertEquals(
            BiometricoAmbito::NO_RESUELTO,
            BiometricoAmbito::fromClaveCatalogo('A1', 'DIR. GRAL. SEG. PUB. Y TTO.', null, true, false)
        );
    }

    // =========================================================================
    // CORRECCIÓN B: INTEGRIDAD DE LA ASOCIACIÓN BIOMÉTRICA (PRUEBAS OBLIGATORIAS)
    // =========================================================================

    public function test_asociacion_mismo_cuip_y_ambito_permitida(): void
    {
        $cuip = 'MATCH123456789012';
        $expediente = BiometricoExpediente::create([
            'cuip' => $cuip,
            'ambito' => BiometricoAmbito::ESTATALES,
        ]);

        $solicitud = $this->createSolicitud();
        $elemento = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => $cuip,
            'ambito_biometrico' => BiometricoAmbito::ESTATALES,
            'estado_clasificacion_ambito' => BiometricoClasificacionEstado::VALIDADA,
        ]);

        $service = new BiometricResolverService();
        $asociado = $service->asociarExpedienteAElemento($elemento, $expediente);

        $this->assertEquals($expediente->id, $asociado->biometrico_expediente_id);
        $this->assertDatabaseHas('solicitud_elementos', [
            'id' => $elemento->id,
            'biometrico_expediente_id' => $expediente->id,
        ]);
    }

    public function test_asociacion_cuip_diferente_rechazada(): void
    {
        $expediente = BiometricoExpediente::create([
            'cuip' => 'CUIP_EXPEDIENTE_A',
            'ambito' => BiometricoAmbito::ESTATALES,
        ]);

        $solicitud = $this->createSolicitud();
        $elemento = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIP_ELEMENTO_B',
            'ambito_biometrico' => BiometricoAmbito::ESTATALES,
            'estado_clasificacion_ambito' => BiometricoClasificacionEstado::VALIDADA,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Discrepancia de identidad');

        $service = new BiometricResolverService();
        $service->asociarExpedienteAElemento($elemento, $expediente);
    }

    public function test_asociacion_directa_en_modelo_bloqueada_por_cuip_diferente(): void
    {
        $expediente = BiometricoExpediente::create([
            'cuip' => 'CUIP_EXPEDIENTE_A',
            'ambito' => BiometricoAmbito::ESTATALES,
        ]);

        $solicitud = $this->createSolicitud();
        $elemento = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIP_ELEMENTO_B',
            'ambito_biometrico' => BiometricoAmbito::ESTATALES,
            'estado_clasificacion_ambito' => BiometricoClasificacionEstado::VALIDADA,
        ]);

        $this->expectException(InvalidArgumentException::class);

        // Intento de evasión asignando directamente en el modelo Eloquent
        $elemento->biometrico_expediente_id = $expediente->id;
        $elemento->save();
    }

    public function test_asociacion_ambito_cruzado_rechazada(): void
    {
        $cuip = 'CROSSAMBITO123456';
        $expediente = BiometricoExpediente::create([
            'cuip' => $cuip,
            'ambito' => BiometricoAmbito::PREVENCION, // Expediente es de Prevención
        ]);

        $solicitud = $this->createSolicitud();
        $elemento = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => $cuip,
            'ambito_biometrico' => BiometricoAmbito::ESTATALES, // Elemento es de Estatales
            'estado_clasificacion_ambito' => BiometricoClasificacionEstado::VALIDADA,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Discrepancia de ámbito');

        $service = new BiometricResolverService();
        $service->asociarExpedienteAElemento($elemento, $expediente);
    }

    public function test_asociacion_con_ambito_no_resuelto_rechazada(): void
    {
        $cuip = 'UNRESOLVEDCUIP123';
        $expediente = BiometricoExpediente::create([
            'cuip' => $cuip,
            'ambito' => BiometricoAmbito::ESTATALES,
        ]);

        $solicitud = $this->createSolicitud();
        $elemento = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => $cuip,
            'ambito_biometrico' => BiometricoAmbito::NO_RESUELTO,
            'estado_clasificacion_ambito' => BiometricoClasificacionEstado::PROVISIONAL,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NO_RESUELTO');

        $service = new BiometricResolverService();
        $service->asociarExpedienteAElemento($elemento, $expediente);
    }

    public function test_asociacion_con_clasificacion_provisional_rechazada(): void
    {
        $cuip = 'PROVISIONALCUIP12';
        $expediente = BiometricoExpediente::create([
            'cuip' => $cuip,
            'ambito' => BiometricoAmbito::ESTATALES,
        ]);

        $solicitud = $this->createSolicitud();
        $elemento = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => $cuip,
            'ambito_biometrico' => BiometricoAmbito::ESTATALES,
            'estado_clasificacion_ambito' => BiometricoClasificacionEstado::PROVISIONAL, // Provisional
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('clasificación provisional');

        $service = new BiometricResolverService();
        $service->asociarExpedienteAElemento($elemento, $expediente);
    }

    public function test_reasociacion_o_modificacion_con_credencial_emitida_inmutable(): void
    {
        $cuip = 'ISSUED12345678901';
        $expediente = BiometricoExpediente::create([
            'cuip' => $cuip,
            'ambito' => BiometricoAmbito::ESTATALES,
        ]);

        $solicitud = $this->createSolicitud();
        $elemento = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => $cuip,
            'ambito_biometrico' => BiometricoAmbito::ESTATALES,
            'estado_clasificacion_ambito' => BiometricoClasificacionEstado::VALIDADA,
            'biometrico_expediente_id' => $expediente->id,
        ]);

        CredencialEmitida::create([
            'solicitud_id' => $solicitud->id,
            'solicitud_elemento_id' => $elemento->id,
            'folio' => 'FOLIO-TEST-001',
            'numero_tarjeta' => 'TARJETA-001',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inmutable');

        $service = new BiometricResolverService();
        $service->desasociarExpedienteDeElemento($elemento);
    }

    // =========================================================================
    // CORRECCIÓN C: COMPLETITUD BIOMÉTRICA E INTEGRIDAD TÉCNICA
    // =========================================================================

    public function test_completitud_tecnica_con_4_archivos_validos(): void
    {
        $cuip = 'FULLINTEGRITYCUIP1';
        $foto = $this->createArchivo(['categoria' => BiometricoCategoria::FOTO, 'cuip_normalizado' => $cuip]);
        $firma = $this->createArchivo(['categoria' => BiometricoCategoria::FIRMA, 'cuip_normalizado' => $cuip]);
        $pulgarDer = $this->createArchivo(['categoria' => BiometricoCategoria::PULGAR_DERECHO, 'cuip_normalizado' => $cuip]);
        $pulgarIzq = $this->createArchivo(['categoria' => BiometricoCategoria::PULGAR_IZQUIERDO, 'cuip_normalizado' => $cuip]);

        $expediente = new BiometricoExpediente([
            'cuip' => $cuip,
            'ambito' => BiometricoAmbito::ESTATALES,
            'foto_archivo_id' => $foto->id,
            'firma_archivo_id' => $firma->id,
            'pulgar_derecho_archivo_id' => $pulgarDer->id,
            'pulgar_izquierdo_archivo_id' => $pulgarIzq->id,
        ]);

        $expediente->recalcularCompletitud();
        $expediente->save();

        $this->assertTrue($expediente->es_completo);
        $this->assertEquals(4, $expediente->total_biometricos);
        $this->assertEmpty($expediente->faltantes);
        $this->assertTrue($expediente->tieneFoto());
        $this->assertTrue($expediente->tieneFirma());
        $this->assertTrue($expediente->tienePulgarDerecho());
        $this->assertTrue($expediente->tienePulgarIzquierdo());
        $this->assertTrue($expediente->esCompletoEstructural());
        $this->assertTrue($expediente->esIntegroTecnico());
    }

    public function test_completitud_falla_si_archivo_esta_desaparecido_o_no_vigente(): void
    {
        $cuip = 'DESAPARECIDOCUIP1';
        $foto = $this->createArchivo([
            'categoria' => BiometricoCategoria::FOTO,
            'cuip_normalizado' => $cuip,
            'estado_archivo' => BiometricoEstadoArchivo::DESAPARECIDO, // Desaparecido en disco
        ]);
        $firma = $this->createArchivo([
            'categoria' => BiometricoCategoria::FIRMA,
            'cuip_normalizado' => $cuip,
            'es_vigente' => false, // No vigente
        ]);
        $pulgarDer = $this->createArchivo(['categoria' => BiometricoCategoria::PULGAR_DERECHO, 'cuip_normalizado' => $cuip]);
        $pulgarIzq = $this->createArchivo(['categoria' => BiometricoCategoria::PULGAR_IZQUIERDO, 'cuip_normalizado' => $cuip]);

        $expediente = new BiometricoExpediente([
            'cuip' => $cuip,
            'ambito' => BiometricoAmbito::ESTATALES,
            'foto_archivo_id' => $foto->id,
            'firma_archivo_id' => $firma->id,
            'pulgar_derecho_archivo_id' => $pulgarDer->id,
            'pulgar_izquierdo_archivo_id' => $pulgarIzq->id,
        ]);

        $expediente->recalcularCompletitud();

        // Aunque tiene 4 IDs no nulos, NO es técnicamente completo
        $this->assertTrue($expediente->esCompletoEstructural());
        $this->assertFalse($expediente->es_completo);
        $this->assertFalse($expediente->esIntegroTecnico());
        $this->assertEquals(2, $expediente->total_biometricos);
        $this->assertContains('foto_desaparecido', $expediente->faltantes);
        $this->assertContains('firma_no_vigente', $expediente->faltantes);
    }

    public function test_completitud_falla_por_discrepancia_de_cuip_o_categoria(): void
    {
        $cuip = 'DISCREPANCIACUIP1';
        $foto = $this->createArchivo([
            'categoria' => BiometricoCategoria::FIRMA, // Categoría incorrecta (firma en vez de foto)
            'cuip_normalizado' => $cuip,
        ]);
        $firma = $this->createArchivo([
            'categoria' => BiometricoCategoria::FIRMA,
            'cuip_normalizado' => 'OTRO_CUIP_ERRONEO', // CUIP discrepante
        ]);
        $pulgarDer = $this->createArchivo(['categoria' => BiometricoCategoria::PULGAR_DERECHO, 'cuip_normalizado' => $cuip]);
        $pulgarIzq = $this->createArchivo(['categoria' => BiometricoCategoria::PULGAR_IZQUIERDO, 'cuip_normalizado' => $cuip]);

        $expediente = new BiometricoExpediente([
            'cuip' => $cuip,
            'ambito' => BiometricoAmbito::ESTATALES,
            'foto_archivo_id' => $foto->id,
            'firma_archivo_id' => $firma->id,
            'pulgar_derecho_archivo_id' => $pulgarDer->id,
            'pulgar_izquierdo_archivo_id' => $pulgarIzq->id,
        ]);

        $expediente->recalcularCompletitud();

        $this->assertFalse($expediente->es_completo);
        $this->assertContains('foto_categoria_incorrecta', $expediente->faltantes);
        $this->assertContains('firma_discrepancia_cuip', $expediente->faltantes);
    }

    // =========================================================================
    // CORRECCIÓN D: TRAZABILIDAD Y SNAPSHOTS DE AUDITORÍA HISTÓRICA
    // =========================================================================

    public function test_auditoria_biometrica_con_snapshots_inmutables(): void
    {
        $cuip = 'AUDITSNAPSHOTCUIP1';
        $archivo = $this->createArchivo([
            'categoria' => BiometricoCategoria::FOTO,
            'cuip_normalizado' => $cuip,
            'sha256' => 'abc123sha256hashverificadoenindexacion000000000000000000000000000000',
            'ruta_relativa' => 'ESTATALES/FOTO/TEST_SNAP.jpg',
        ]);

        $expediente = BiometricoExpediente::create([
            'cuip' => $cuip,
            'ambito' => BiometricoAmbito::ESTATALES,
            'foto_archivo_id' => $archivo->id,
        ]);

        $solicitud = $this->createSolicitud();
        $elemento = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => $cuip,
            'ambito_biometrico' => BiometricoAmbito::ESTATALES,
            'estado_clasificacion_ambito' => BiometricoClasificacionEstado::VALIDADA,
            'biometrico_expediente_id' => $expediente->id,
        ]);

        // Registrar auditoría con la factoría de snapshots
        $auditoria = BiometricoAuditoria::registrarAcceso(
            evento: 'stream_fotografia',
            actor: $this->user,
            expediente: $expediente,
            archivo: $archivo,
            elemento: $elemento,
            ipAddress: '192.168.1.100',
            userAgent: 'Mozilla/5.0 Auditor Harness',
            detalles: ['motivo' => 'verificacion_emision']
        );

        // Verificamos que los snapshots escalares inmutables se congelaron
        $this->assertEquals($this->user->id, $auditoria->actor_user_id_historico);
        $this->assertEquals('auditor.loc@edomex.gob.mx', $auditoria->actor_email_historico);
        $this->assertEquals($cuip, $auditoria->cuip_snapshot);
        $this->assertEquals('ESTATALES', $auditoria->ambito_snapshot);
        $this->assertEquals('foto', $auditoria->tipo_biometrico_snapshot);
        $this->assertEquals('ESTATALES/FOTO/TEST_SNAP.jpg', $auditoria->ruta_relativa_snapshot);
        $this->assertEquals('abc123sha256hashverificadoenindexacion000000000000000000000000000000', $auditoria->sha256_snapshot);

        // Si el archivo físico o expediente fuera desvinculado o archivado:
        $archivo->delete();
        $auditoria->refresh();

        // La FK pasa a NULL (ON DELETE SET NULL), pero los snapshots PERMANECEN INTACTOS
        $this->assertNull($auditoria->biometrico_archivo_id);
        $this->assertEquals($cuip, $auditoria->cuip_snapshot);
        $this->assertEquals('ESTATALES/FOTO/TEST_SNAP.jpg', $auditoria->ruta_relativa_snapshot);
        $this->assertEquals('auditor.loc@edomex.gob.mx', $auditoria->actor_email_historico);
    }

    public function test_registros_de_auditoria_son_estrictamente_inmutables(): void
    {
        $auditoria = BiometricoAuditoria::create([
            'evento' => 'consulta_prueba',
            'ip_address' => '127.0.0.1',
            'actor_email_historico' => 'test@gob.mx',
        ]);

        // 1. Intento de UPDATE debe ser rechazado
        try {
            $auditoria->evento = 'evento_alterado';
            $auditoria->save();
            $this->fail('Se esperaba RuntimeException por intento de modificación en auditoría.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('inmutables', $e->getMessage());
        }

        // 2. Intento de DELETE debe ser rechazado
        try {
            $auditoria->delete();
            $this->fail('Se esperaba RuntimeException por intento de eliminación en auditoría.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('solo inserción', $e->getMessage());
        }
    }
}
