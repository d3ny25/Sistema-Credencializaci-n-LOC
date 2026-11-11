<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LocArma;
use App\Models\LocCatalogoOrganizacional;
use App\Models\LocCatalogoVersion;
use App\Models\LocImportBatch;
use App\Models\LocPersona;
use App\Models\LocVigenciaCredencial;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Models\User;
use App\Services\LocCredentialsPolicyService;
use App\Services\LocCredentialsXlsImporter;
use App\Services\SolicitudPrefiltroService;
use App\Support\MotivoDescarte;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SolicitudClasificacionMotivosTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private LocImportBatch $personalBatch;
    private LocImportBatch $armamentoBatch;
    private LocImportBatch $vigenciasBatch;
    private LocCatalogoVersion $catalogoVersion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->personalBatch = LocImportBatch::create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'Personal_Oficial_2026.xlsx',
            'estado' => 'completado',
            'fecha_confirmacion' => Carbon::now()->subDays(5),
            'usuario_id' => $this->user->id,
            'total_leidas' => 100,
            'total_insertadas' => 100,
        ]);

        $this->armamentoBatch = LocImportBatch::create([
            'tipo_fuente' => 'armamento_loc',
            'nombre_archivo' => 'Armamento_Oficial_2026.xlsx',
            'estado' => 'completado',
            'fecha_confirmacion' => Carbon::now()->subDays(4),
            'usuario_id' => $this->user->id,
            'total_leidas' => 100,
            'total_insertadas' => 100,
        ]);

        $this->vigenciasBatch = LocImportBatch::create([
            'tipo_fuente' => LocCredentialsXlsImporter::SOURCE_TYPE,
            'nombre_archivo' => 'Vigencias_Oficiales_2026.xlsx',
            'estado' => 'completado',
            'fecha_confirmacion' => Carbon::now()->subDays(3),
            'usuario_id' => $this->user->id,
            'total_leidas' => 100,
            'total_insertadas' => 100,
        ]);

        $catalogoBatch = LocImportBatch::create([
            'tipo_fuente' => 'catalogo_organizacional',
            'nombre_archivo' => 'Catalogo_Oficial_2026.xlsx',
            'estado' => 'completado',
            'fecha_confirmacion' => Carbon::now()->subDays(2),
            'usuario_id' => $this->user->id,
            'total_leidas' => 50,
            'total_insertadas' => 50,
        ]);

        $this->catalogoVersion = LocCatalogoVersion::create([
            'import_batch_id' => $catalogoBatch->id,
            'version_numero' => 1,
            'es_vigente' => true,
            'total_registros' => 50,
            'archivo_nombre' => 'Catalogo_Oficial_2026.xlsx',
            'usuario_id' => $this->user->id,
            'fecha_activacion' => Carbon::now()->subDays(2),
        ]);
    }

    private function createArma(string $matricula, string $estatus = 'FISICA'): LocArma
    {
        return LocArma::create([
            'import_batch_id' => $this->armamentoBatch->id,
            'matricula' => $matricula,
            'estatus' => $estatus,
            'tipo' => 'PISTOLA',
            'marca' => 'GLOCK',
            'calibre' => '9MM',
            'modelo' => '17',
        ]);
    }

    private function createPersona(string $cuip, string $nombre = 'JUAN PEREZ'): LocPersona
    {
        return LocPersona::create([
            'import_batch_id' => $this->personalBatch->id,
            'cuip' => $cuip,
            'nombre' => $nombre,
            'primer_apellido' => 'PEREZ',
            'segundo_apellido' => 'LOPEZ',
            'estatus' => 'ACTIVO',
        ]);
    }

    private function createSolicitud(): SolicitudCredencial
    {
        return SolicitudCredencial::create([
            'numero_tarjeta' => 'CE/TA25-27/50001/2026',
            'consecutivo_tarjeta' => 50001,
            'numero_oficio' => 'OF-TEST-' . uniqid(),
            'fecha_recepcion' => now()->toDateString(),
            'estado_general' => 'recibida',
            'usuario_responsable_id' => $this->user->id,
            'total_elementos' => 0,
            'total_aptos' => 0,
            'total_descartados' => 0,
        ]);
    }

    // ==========================================
    // A. REGLAS INDIVIDUALES DE CLASIFICACIÓN (14 REGLAS)
    // ==========================================

    /** 1. CUIP Vacío o Placeholder -> CUIP_VACIO */
    public function test_01_cuip_vacio_asigna_codigo_cuip_vacio(): void
    {
        $solicitud = $this->createSolicitud();
        $arma = $this->createArma('MAT-C01');

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'S/N',
            'matricula_corta' => 'MAT-C01',
        ]);

        app(SolicitudPrefiltroService::class)->evaluate($solicitud, $this->user);

        $elem->refresh();
        $this->assertSame('Descartado', $elem->resultado_prefiltro);
        $this->assertContains(MotivoDescarte::CUIP_VACIO, $elem->codigos_descarte);
        $this->assertStringContainsString('no cuenta con una clave CUIP válida', $elem->motivos_descarte[0]);
    }

    /** 2. CUIP Duplicado en la misma solicitud -> CUIP_DUPLICADO */
    public function test_02_cuip_duplicado_asigna_codigo_cuip_duplicado(): void
    {
        $solicitud = $this->createSolicitud();
        $this->createPersona('CUIPDUP12345678901');
        $this->createArma('MAT-DUP1');
        $this->createArma('MAT-DUP2');

        $elem1 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPDUP12345678901',
            'matricula_corta' => 'MAT-DUP1',
        ]);
        $elem2 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 3,
            'cuip' => 'CUIPDUP12345678901',
            'matricula_corta' => 'MAT-DUP2',
        ]);

        app(SolicitudPrefiltroService::class)->evaluate($solicitud, $this->user);

        $elem1->refresh();
        $elem2->refresh();
        $this->assertContains(MotivoDescarte::CUIP_DUPLICADO, $elem1->codigos_descarte);
        $this->assertContains(MotivoDescarte::CUIP_DUPLICADO, $elem2->codigos_descarte);
    }

    /** 3. CUIP No Localizado en Personal LOC -> CUIP_NO_LOCALIZADO */
    public function test_03_cuip_no_localizado_asigna_codigo_cuip_no_localizado(): void
    {
        $solicitud = $this->createSolicitud();
        $this->createArma('MAT-NOLOC1');

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPNOEXISTE9999999',
            'matricula_corta' => 'MAT-NOLOC1',
        ]);

        app(SolicitudPrefiltroService::class)->evaluate($solicitud, $this->user);

        $elem->refresh();
        $this->assertContains(MotivoDescarte::CUIP_NO_LOCALIZADO, $elem->codigos_descarte);
    }

    /** 4. Credencial con ALTA Activa -> EXP_ALTA_ACTIVA */
    public function test_04_credencial_alta_activa_asigna_codigo_exp_alta_activa(): void
    {
        $solicitud = $this->createSolicitud();
        $persona = $this->createPersona('CUIPALTA1234567890');
        $this->createArma('MAT-ALTA1');

        LocVigenciaCredencial::create([
            'import_batch_id' => $this->vigenciasBatch->id,
            'cuip' => 'CUIPALTA1234567890',
            'persona_id' => $persona->id,
            'estatus_credencial' => LocCredentialsPolicyService::STATUS_ALTA,
            'numero_credencial' => 'OM2527E99999',
            'numero_oficio' => 'OF-VIG-001',
            'fecha_baja' => null,
        ]);

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPALTA1234567890',
            'matricula_corta' => 'MAT-ALTA1',
        ]);

        app(SolicitudPrefiltroService::class)->evaluate($solicitud, $this->user);

        $elem->refresh();
        $this->assertContains(MotivoDescarte::EXP_ALTA_ACTIVA, $elem->codigos_descarte);
    }

    /** 5. Credencial con SITUACIÓN ESPECIAL -> EXP_SITUACION_ESPECIAL */
    public function test_05_credencial_situacion_especial_asigna_codigo_exp_situacion_especial(): void
    {
        $solicitud = $this->createSolicitud();
        $persona = $this->createPersona('CUIPESP12345678901');
        $this->createArma('MAT-ESP1');

        LocVigenciaCredencial::create([
            'import_batch_id' => $this->vigenciasBatch->id,
            'cuip' => 'CUIPESP12345678901',
            'persona_id' => $persona->id,
            'estatus_credencial' => LocCredentialsPolicyService::STATUS_SITUACION_ESPECIAL,
            'motivo_situacion_especial' => 'PROCEDIMIENTO ADMINISTRATIVO ACTIVO',
            'fecha_solicitud' => '2026-01-01',
        ]);

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPESP12345678901',
            'matricula_corta' => 'MAT-ESP1',
        ]);

        app(SolicitudPrefiltroService::class)->evaluate($solicitud, $this->user);

        $elem->refresh();
        $this->assertContains(MotivoDescarte::EXP_SITUACION_ESPECIAL, $elem->codigos_descarte);
    }

    /** 6. Credencial Cancelada -> EXP_CANCELACION */
    public function test_06_credencial_cancelada_asigna_codigo_exp_cancelacion(): void
    {
        $solicitud = $this->createSolicitud();
        $persona = $this->createPersona('CUIPCANC1234567890');
        $this->createArma('MAT-CANC1');

        LocVigenciaCredencial::create([
            'import_batch_id' => $this->vigenciasBatch->id,
            'cuip' => 'CUIPCANC1234567890',
            'persona_id' => $persona->id,
            'estatus_credencial' => LocCredentialsPolicyService::STATUS_CANCELACION,
            'fecha_solicitud' => '2026-01-01',
        ]);

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPCANC1234567890',
            'matricula_corta' => 'MAT-CANC1',
        ]);

        app(SolicitudPrefiltroService::class)->evaluate($solicitud, $this->user);

        $elem->refresh();
        $this->assertContains(MotivoDescarte::EXP_CANCELACION, $elem->codigos_descarte);
    }

    /** 7. Estatus de Credencial Desconocido -> EXP_ESTATUS_DESCONOCIDO */
    public function test_07_estatus_credencial_desconocido_asigna_codigo_exp_estatus_desconocido(): void
    {
        $solicitud = $this->createSolicitud();
        $persona = $this->createPersona('CUIPDESC1234567890');
        $this->createArma('MAT-DESC1');

        LocVigenciaCredencial::create([
            'import_batch_id' => $this->vigenciasBatch->id,
            'cuip' => 'CUIPDESC1234567890',
            'persona_id' => $persona->id,
            'estatus_credencial' => 'SUSPENDIDO_PROVISIONAL',
            'fecha_solicitud' => '2026-01-01',
        ]);

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPDESC1234567890',
            'matricula_corta' => 'MAT-DESC1',
        ]);

        app(SolicitudPrefiltroService::class)->evaluate($solicitud, $this->user);

        $elem->refresh();
        $this->assertContains(MotivoDescarte::EXP_ESTATUS_DESCONOCIDO, $elem->codigos_descarte);
    }

    /** 8. Sin Asignación de Armamento -> ARM_SIN_ASIGNACION */
    public function test_08_sin_armas_asigna_codigo_arm_sin_asignacion(): void
    {
        $solicitud = $this->createSolicitud();
        $this->createPersona('CUIPSINARM12345678');

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPSINARM12345678',
            'matricula_corta' => null,
            'matricula_larga' => 'S/N',
        ]);

        app(SolicitudPrefiltroService::class)->evaluate($solicitud, $this->user);

        $elem->refresh();
        $this->assertContains(MotivoDescarte::ARM_SIN_ASIGNACION, $elem->codigos_descarte);
    }

    /** 9. Arma Corta No Localizada -> ARM_CORTA_NO_LOCALIZADA */
    public function test_09_arma_corta_no_localizada_asigna_codigo_arm_corta_no_localizada(): void
    {
        $solicitud = $this->createSolicitud();
        $this->createPersona('CUIPACNOLOC1234567');

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPACNOLOC1234567',
            'matricula_corta' => 'MAT-INEXISTENTE-99',
        ]);

        app(SolicitudPrefiltroService::class)->evaluate($solicitud, $this->user);

        $elem->refresh();
        $this->assertContains(MotivoDescarte::ARM_CORTA_NO_LOCALIZADA, $elem->codigos_descarte);
    }

    /** 10. Estatus Inválido de Arma Corta -> ARM_CORTA_ESTATUS_INVALIDO */
    public function test_10_arma_corta_estatus_invalido_asigna_codigo_arm_corta_estatus_invalido(): void
    {
        $solicitud = $this->createSolicitud();
        $this->createPersona('CUIPACINV123456789');
        $this->createArma('MAT-ADISP-01', 'A DISP');

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPACINV123456789',
            'matricula_corta' => 'MAT-ADISP-01',
        ]);

        app(SolicitudPrefiltroService::class)->evaluate($solicitud, $this->user);

        $elem->refresh();
        $this->assertContains(MotivoDescarte::ARM_CORTA_ESTATUS_INVALIDO, $elem->codigos_descarte);
    }

    /** 11. Cupo Excedido de Arma Corta -> ARM_CORTA_CUPO_EXCEDIDO */
    public function test_11_arma_corta_cupo_excedido_asigna_codigo_arm_corta_cupo_excedido(): void
    {
        $solicitud = $this->createSolicitud();
        $this->createPersona('CUIPACCUP123456789');
        $arma = $this->createArma('MAT-CUPO-01', 'FISICA');

        // Asignar 3 vigencias activas al arma
        for ($i = 1; $i <= 3; $i++) {
            LocVigenciaCredencial::create([
                'import_batch_id' => $this->vigenciasBatch->id,
                'cuip' => "CUIPOTHER{$i}12345678",
                'arma_corta_id' => $arma->id,
                'matricula_corta' => $arma->matricula,
                'estatus_credencial' => LocCredentialsPolicyService::STATUS_ALTA,
                'fecha_baja' => null,
            ]);
        }

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPACCUP123456789',
            'matricula_corta' => 'MAT-CUPO-01',
        ]);

        app(SolicitudPrefiltroService::class)->evaluate($solicitud, $this->user);

        $elem->refresh();
        $this->assertContains(MotivoDescarte::ARM_CORTA_CUPO_EXCEDIDO, $elem->codigos_descarte);
    }

    /** 12. Arma Larga No Localizada -> ARM_LARGA_NO_LOCALIZADA */
    public function test_12_arma_larga_no_localizada_asigna_codigo_arm_larga_no_localizada(): void
    {
        $solicitud = $this->createSolicitud();
        $this->createPersona('CUIPALNOLOC1234567');

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPALNOLOC1234567',
            'matricula_larga' => 'MAT-LARGA-NOEXISTE',
        ]);

        app(SolicitudPrefiltroService::class)->evaluate($solicitud, $this->user);

        $elem->refresh();
        $this->assertContains(MotivoDescarte::ARM_LARGA_NO_LOCALIZADA, $elem->codigos_descarte);
    }

    /** 13. Estatus Inválido de Arma Larga -> ARM_LARGA_ESTATUS_INVALIDO */
    public function test_13_arma_larga_estatus_invalido_asigna_codigo_arm_larga_estatus_invalido(): void
    {
        $solicitud = $this->createSolicitud();
        $this->createPersona('CUIPALINV123456789');
        $this->createArma('MAT-LARGA-EXTRAV', 'EXTRAVIADA');

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPALINV123456789',
            'matricula_larga' => 'MAT-LARGA-EXTRAV',
        ]);

        app(SolicitudPrefiltroService::class)->evaluate($solicitud, $this->user);

        $elem->refresh();
        $this->assertContains(MotivoDescarte::ARM_LARGA_ESTATUS_INVALIDO, $elem->codigos_descarte);
    }

    /** 14. Cupo Excedido de Arma Larga -> ARM_LARGA_CUPO_EXCEDIDO */
    public function test_14_arma_larga_cupo_excedido_asigna_codigo_arm_larga_cupo_excedido(): void
    {
        $solicitud = $this->createSolicitud();
        $this->createPersona('CUIPALCUP123456789');
        $arma = $this->createArma('MAT-LARGA-CUPO', 'FISICA');

        for ($i = 1; $i <= 3; $i++) {
            LocVigenciaCredencial::create([
                'import_batch_id' => $this->vigenciasBatch->id,
                'cuip' => "CUIPLARGA{$i}1234567",
                'arma_larga_id' => $arma->id,
                'matricula_larga' => $arma->matricula,
                'estatus_credencial' => LocCredentialsPolicyService::STATUS_ALTA,
                'fecha_baja' => null,
            ]);
        }

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPALCUP123456789',
            'matricula_larga' => 'MAT-LARGA-CUPO',
        ]);

        app(SolicitudPrefiltroService::class)->evaluate($solicitud, $this->user);

        $elem->refresh();
        $this->assertContains(MotivoDescarte::ARM_LARGA_CUPO_EXCEDIDO, $elem->codigos_descarte);
    }

    // ==========================================
    // B. COEXISTENCIA Y ACUMULACIÓN
    // ==========================================

    /** 15. Coexistencia de múltiples códigos simultáneos sin pérdida ni duplicación */
    public function test_15_coexistencia_de_multiples_codigos_simultaneos(): void
    {
        $solicitud = $this->createSolicitud();
        // CUIP no registrado en personal
        // Arma corta no existente
        // Arma larga no existente
        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPTRIPLEFALLO999',
            'matricula_corta' => 'MAT-CORTA-NOEXIST',
            'matricula_larga' => 'MAT-LARGA-NOEXIST',
        ]);

        app(SolicitudPrefiltroService::class)->evaluate($solicitud, $this->user);

        $elem->refresh();
        $this->assertSame('Descartado', $elem->resultado_prefiltro);
        $this->assertCount(3, $elem->codigos_descarte);
        $this->assertContains(MotivoDescarte::CUIP_NO_LOCALIZADO, $elem->codigos_descarte);
        $this->assertContains(MotivoDescarte::ARM_CORTA_NO_LOCALIZADA, $elem->codigos_descarte);
        $this->assertContains(MotivoDescarte::ARM_LARGA_NO_LOCALIZADA, $elem->codigos_descarte);
        $this->assertCount(3, array_unique($elem->codigos_descarte), 'No deben existir duplicados en el array');
    }

    // ==========================================
    // C. DIFERENCIACIÓN ALTA VS SITUACIÓN ESPECIAL
    // ==========================================

    /** 16. Diferenciación estricta entre ALTA activa y Situación Especial */
    public function test_16_diferenciacion_estricta_alta_vs_situacion_especial(): void
    {
        $solicitud = $this->createSolicitud();
        $personaAlta = $this->createPersona('CUIPDIFALTA1234567');
        $personaSitEsp = $this->createPersona('CUIPDIFSITESP12345');
        $this->createArma('MAT-DIF1');
        $this->createArma('MAT-DIF2');

        // Registro 1: ALTA activa
        LocVigenciaCredencial::create([
            'import_batch_id' => $this->vigenciasBatch->id,
            'cuip' => 'CUIPDIFALTA1234567',
            'persona_id' => $personaAlta->id,
            'estatus_credencial' => LocCredentialsPolicyService::STATUS_ALTA,
            'fecha_baja' => null,
        ]);

        // Registro 2: Situación Especial
        LocVigenciaCredencial::create([
            'import_batch_id' => $this->vigenciasBatch->id,
            'cuip' => 'CUIPDIFSITESP12345',
            'persona_id' => $personaSitEsp->id,
            'estatus_credencial' => LocCredentialsPolicyService::STATUS_SITUACION_ESPECIAL,
            'motivo_situacion_especial' => 'SUSPENSION JUDICIAL',
            'fecha_solicitud' => '2026-01-01',
        ]);

        $elemAlta = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPDIFALTA1234567',
            'matricula_corta' => 'MAT-DIF1',
        ]);
        $elemSitEsp = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 3,
            'cuip' => 'CUIPDIFSITESP12345',
            'matricula_corta' => 'MAT-DIF2',
        ]);

        app(SolicitudPrefiltroService::class)->evaluate($solicitud, $this->user);

        $elemAlta->refresh();
        $elemSitEsp->refresh();

        $this->assertContains(MotivoDescarte::EXP_ALTA_ACTIVA, $elemAlta->codigos_descarte);
        $this->assertNotContains(MotivoDescarte::EXP_SITUACION_ESPECIAL, $elemAlta->codigos_descarte);

        $this->assertContains(MotivoDescarte::EXP_SITUACION_ESPECIAL, $elemSitEsp->codigos_descarte);
        $this->assertNotContains(MotivoDescarte::EXP_ALTA_ACTIVA, $elemSitEsp->codigos_descarte);
    }

    // ==========================================
    // D. ELEMENTO APTO Y CONSERVACIÓN HISTÓRICA
    // ==========================================

    /** 17. Elemento apto no recibe códigos de descarte */
    public function test_17_elemento_apto_no_recibe_codigos_de_descarte(): void
    {
        $solicitud = $this->createSolicitud();
        $this->createPersona('CUIPAPTO1234567890');
        $this->createArma('MAT-APTO1');

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPAPTO1234567890',
            'matricula_corta' => 'MAT-APTO1',
        ]);

        app(SolicitudPrefiltroService::class)->evaluate($solicitud, $this->user);

        $elem->refresh();
        $this->assertSame('Apto', $elem->resultado_prefiltro);
        $this->assertSame('apto', $elem->estado_tramite);
        $this->assertNull($elem->motivos_descarte);
        $this->assertNull($elem->codigos_descarte);
        $this->assertNull($elem->motivo_rechazo);
    }

    /** 18. Conservación íntegra de textos y dictámenes */
    public function test_18_conservacion_integra_de_textos_y_dictamenes(): void
    {
        $solicitud = $this->createSolicitud();
        $this->createArma('MAT-CONS1', 'A DISP');

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPCONS1234567890',
            'matricula_corta' => 'MAT-CONS1',
        ]);

        app(SolicitudPrefiltroService::class)->evaluate($solicitud, $this->user);

        $elem->refresh();
        $textoOriginal = $elem->motivos_descarte;
        $rechazoOriginal = $elem->motivo_rechazo;
        $dictamenOriginal = $elem->resultado_prefiltro;

        $this->assertNotEmpty($textoOriginal);
        $this->assertSame('Descartado', $dictamenOriginal);
        $this->assertSame(implode(' | ', $textoOriginal), $rechazoOriginal);

        // Reevaluar y comprobar inmutabilidad conceptual de textos
        app(SolicitudPrefiltroService::class)->evaluate($solicitud, $this->user);
        $elem->refresh();

        $this->assertSame($textoOriginal, $elem->motivos_descarte);
        $this->assertSame($rechazoOriginal, $elem->motivo_rechazo);
        $this->assertSame($dictamenOriginal, $elem->resultado_prefiltro);
    }

    // ==========================================
    // E. HERRAMIENTA DE BACKFILL, SEGURIDAD Y TRANSACCIONALIDAD
    // ==========================================

    /** 19. Comando sin opciones opera en modo simulación (dry-run) y no modifica la base de datos */
    public function test_19_comando_sin_opciones_es_dry_run_y_no_escribe(): void
    {
        $solicitud = $this->createSolicitud();
        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPDRY12345678901',
            'resultado_prefiltro' => 'Descartado',
            'motivos_descarte' => [
                "El CUIP 'CUIPDRY12345678901' no fue localizado en el padrón oficial vigente de personal autorizado de LOC.",
            ],
            'motivo_rechazo' => "El CUIP 'CUIPDRY12345678901' no fue localizado en el padrón oficial vigente de personal autorizado de LOC.",
            'codigos_descarte' => null,
        ]);

        $this->artisan('credencializacion:clasificar-motivos')
            ->expectsOutputToContain('MODO SIMULACIÓN (DRY-RUN) ACTIVO')
            ->expectsOutputToContain('Ningún registro fue modificado en la base de datos')
            ->assertExitCode(0);

        $elem->refresh();
        $this->assertNull($elem->codigos_descarte, 'La ejecución por defecto debe ser dry-run y no modificar registros');
    }

    /** 19b. Comando con flag explícito --dry-run no modifica la base de datos */
    public function test_19b_comando_dry_run_explicito_no_modifica_base_de_datos(): void
    {
        $solicitud = $this->createSolicitud();
        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPDRYEXP12345678',
            'resultado_prefiltro' => 'Descartado',
            'motivos_descarte' => [
                "El CUIP 'CUIPDRYEXP12345678' no fue localizado en el padrón oficial vigente de personal autorizado de LOC.",
            ],
            'motivo_rechazo' => "El CUIP 'CUIPDRYEXP12345678' no fue localizado en el padrón oficial vigente de personal autorizado de LOC.",
            'codigos_descarte' => null,
        ]);

        $this->artisan('credencializacion:clasificar-motivos --dry-run')
            ->expectsOutputToContain('MODO SIMULACIÓN (DRY-RUN) ACTIVO')
            ->assertExitCode(0);

        $elem->refresh();
        $this->assertNull($elem->codigos_descarte, 'En modo --dry-run explícito no debe modificarse la BD');
    }

    /** 19c. Conflicto entre --force y --dry-run es rechazado con error */
    public function test_19c_conflicto_de_opciones_dry_run_y_force_es_rechazado(): void
    {
        $solicitud = $this->createSolicitud();
        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPCONF1234567890',
            'resultado_prefiltro' => 'Descartado',
            'motivos_descarte' => [
                "El CUIP 'CUIPCONF1234567890' no fue localizado en el padrón oficial vigente de personal autorizado de LOC.",
            ],
            'motivo_rechazo' => "El CUIP 'CUIPCONF1234567890' no fue localizado en el padrón oficial vigente de personal autorizado de LOC.",
            'codigos_descarte' => null,
        ]);

        $this->artisan('credencializacion:clasificar-motivos --force --dry-run')
            ->expectsOutputToContain('Conflicto de opciones')
            ->assertExitCode(1);

        $elem->refresh();
        $this->assertNull($elem->codigos_descarte, 'Ante conflicto de opciones la ejecución se aborta');
    }

    /** 20. Comando con --force clasifica correctamente y es idempotente ante reejecuciones */
    public function test_20_comando_con_force_clasifica_e_idempotencia_segunda_ejecucion(): void
    {
        $solicitud = $this->createSolicitud();
        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPREAL1234567890',
            'resultado_prefiltro' => 'Descartado',
            'motivos_descarte' => [
                "El CUIP 'CUIPREAL1234567890' no fue localizado en el padrón oficial vigente de personal autorizado de LOC.",
            ],
            'motivo_rechazo' => "El CUIP 'CUIPREAL1234567890' no fue localizado en el padrón oficial vigente de personal autorizado de LOC.",
            'codigos_descarte' => null,
        ]);

        // Primera ejecución con --force: clasifica
        $this->artisan('credencializacion:clasificar-motivos --force')
            ->assertExitCode(0);

        $elem->refresh();
        $this->assertSame([MotivoDescarte::CUIP_NO_LOCALIZADO], $elem->codigos_descarte);

        // Segunda ejecución con --force: idempotencia absoluta
        $this->artisan('credencializacion:clasificar-motivos --force')
            ->assertExitCode(0);

        $elem->refresh();
        $this->assertSame([MotivoDescarte::CUIP_NO_LOCALIZADO], $elem->codigos_descarte);
    }

    /** 23. Contradicción entre motivos_descarte y motivo_rechazo impide la clasificación */
    public function test_23_contradiccion_historica_impide_clasificacion(): void
    {
        $solicitud = $this->createSolicitud();
        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 3,
            'cuip' => 'CUIPCONTRA12345678',
            'resultado_prefiltro' => 'Descartado',
            'motivos_descarte' => [
                "El CUIP 'CUIPCONTRA12345678' no fue localizado en el padrón oficial vigente de personal autorizado de LOC.",
            ],
            'motivo_rechazo' => 'TEXTO TOTALMENTE DISCREPANTE Y CONTRADICTORIO',
            'codigos_descarte' => null,
        ]);

        $this->artisan('credencializacion:clasificar-motivos --force')
            ->expectsOutputToContain('Contradicción detectada en Elemento ID ' . $elem->id)
            ->assertExitCode(0);

        $elem->refresh();
        // El registro contradictorio debe permanecer bloqueado con codigos_descarte en null
        $this->assertNull($elem->codigos_descarte, 'Un registro con contradicciones debe excluirse de la clasificación');
        $this->assertSame('TEXTO TOTALMENTE DISCREPANTE Y CONTRADICTORIO', $elem->motivo_rechazo);
        $this->assertSame(["El CUIP 'CUIPCONTRA12345678' no fue localizado en el padrón oficial vigente de personal autorizado de LOC."], $elem->motivos_descarte);
    }

    /** 24. Un código existente no se sobrescribe incorrectamente */
    public function test_24_codigo_existente_no_se_sobrescribe_incorrectamente(): void
    {
        $solicitud = $this->createSolicitud();
        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 4,
            'cuip' => 'CUIPEXIS1234567890',
            'resultado_prefiltro' => 'Descartado',
            'motivos_descarte' => [
                "El CUIP 'CUIPEXIS1234567890' no fue localizado en el padrón oficial vigente de personal autorizado de LOC.",
            ],
            'motivo_rechazo' => "El CUIP 'CUIPEXIS1234567890' no fue localizado en el padrón oficial vigente de personal autorizado de LOC.",
            'codigos_descarte' => [MotivoDescarte::CUIP_NO_LOCALIZADO],
        ]);

        $this->artisan('credencializacion:clasificar-motivos --force')
            ->expectsOutputToContain('Todos los elementos elegibles ya cuentan con su clasificación estructurada')
            ->assertExitCode(0);

        $elem->refresh();
        $this->assertSame([MotivoDescarte::CUIP_NO_LOCALIZADO], $elem->codigos_descarte);
    }

    /** 25. Error durante la ejecución provoca rollback transaccional total */
    public function test_25_error_durante_ejecucion_provoca_rollback_transaccional(): void
    {
        $solicitud = $this->createSolicitud();
        $elem1 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIPROLL1111111111',
            'resultado_prefiltro' => 'Descartado',
            'motivos_descarte' => [
                "El CUIP 'CUIPROLL1111111111' no fue localizado en el padrón oficial vigente de personal autorizado de LOC.",
            ],
            'motivo_rechazo' => "El CUIP 'CUIPROLL1111111111' no fue localizado en el padrón oficial vigente de personal autorizado de LOC.",
            'codigos_descarte' => null,
        ]);

        $elem2 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPROLL2222222222',
            'resultado_prefiltro' => 'Descartado',
            'motivos_descarte' => [
                "El CUIP 'CUIPROLL2222222222' no fue localizado en el padrón oficial vigente de personal autorizado de LOC.",
            ],
            'motivo_rechazo' => "El CUIP 'CUIPROLL2222222222' no fue localizado en el padrón oficial vigente de personal autorizado de LOC.",
            'codigos_descarte' => null,
        ]);

        // Interceptar la consulta de actualización sobre elem2 para simular fallo a mitad del proceso
        $updateCount = 0;
        DB::listen(function ($query) use (&$updateCount) {
            if (str_contains(strtolower($query->sql), 'update') && str_contains(strtolower($query->sql), 'solicitud_elementos')) {
                $updateCount++;
                if ($updateCount === 2) {
                    throw new \RuntimeException('Error simulado en MariaDB durante persistencia del segundo elemento');
                }
            }
        });

        $this->artisan('credencializacion:clasificar-motivos --force')
            ->expectsOutputToContain('Se realizó rollback total de los cambios')
            ->assertExitCode(1);

        $elem1->refresh();
        $elem2->refresh();

        // Gracias al rollback transaccional, elem1 NO debió quedar guardado
        $this->assertNull($elem1->codigos_descarte, 'El rollback debe revertir elem1 aunque se haya intentado actualizar primero');
        $this->assertNull($elem2->codigos_descarte, 'elem2 debe permanecer nulo tras el error');
    }

    /** 26. Solo cambia codigos_descarte y updated_at permanece intacto junto con campos históricos */
    public function test_26_backfill_solo_cambia_codigos_descarte_y_preserva_updated_at_y_campos_historicos(): void
    {
        $solicitud = $this->createSolicitud();
        $fechaPasada = Carbon::now()->subDays(15)->startOfSecond();

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 7,
            'persona_id' => null,
            'cuip' => 'CUIPSAFE1234567890',
            'matricula_corta' => 'MAT-SAFE-1',
            'matricula_larga' => 'MAT-SAFE-2',
            'arma_corta_id' => null,
            'arma_larga_id' => null,
            'resultado_prefiltro' => 'Descartado',
            'motivos_descarte' => [
                "El CUIP 'CUIPSAFE1234567890' no fue localizado en el padrón oficial vigente de personal autorizado de LOC.",
            ],
            'motivo_rechazo' => "El CUIP 'CUIPSAFE1234567890' no fue localizado en el padrón oficial vigente de personal autorizado de LOC.",
            'codigos_descarte' => null,
            'estado_tramite' => 'descartado',
            'datos_originales_fila' => ['col_a' => 'valor1', 'col_b' => 'valor2'],
        ]);

        // Establecer fecha updated_at fija en el pasado
        DB::table('solicitud_elementos')->where('id', $elem->id)->update([
            'updated_at' => $fechaPasada,
            'created_at' => $fechaPasada,
        ]);
        $elem->refresh();
        $this->assertSame($fechaPasada->toIso8601String(), $elem->updated_at->toIso8601String());

        // Ejecución con --force
        $this->artisan('credencializacion:clasificar-motivos --force')->assertExitCode(0);

        $elem->refresh();

        // 1. codigos_descarte cambió correctamente
        $this->assertSame([MotivoDescarte::CUIP_NO_LOCALIZADO], $elem->codigos_descarte);

        // 2. updated_at NO fue alterado (R-06)
        $this->assertSame($fechaPasada->toIso8601String(), $elem->updated_at->toIso8601String());
        $this->assertSame($fechaPasada->toIso8601String(), $elem->created_at->toIso8601String());

        // 3. Todos los demás campos históricos permanecen inalterados
        $this->assertSame($solicitud->id, $elem->solicitud_id);
        $this->assertSame(7, $elem->numero_fila);
        $this->assertNull($elem->persona_id);
        $this->assertSame('CUIPSAFE1234567890', $elem->cuip);
        $this->assertSame('MAT-SAFE-1', $elem->matricula_corta);
        $this->assertSame('MAT-SAFE-2', $elem->matricula_larga);
        $this->assertSame('Descartado', $elem->resultado_prefiltro);
        $this->assertSame('descartado', $elem->estado_tramite);
        $this->assertSame("El CUIP 'CUIPSAFE1234567890' no fue localizado en el padrón oficial vigente de personal autorizado de LOC.", $elem->motivo_rechazo);
        $this->assertSame(["El CUIP 'CUIPSAFE1234567890' no fue localizado en el padrón oficial vigente de personal autorizado de LOC."], $elem->motivos_descarte);
        $this->assertSame(['col_a' => 'valor1', 'col_b' => 'valor2'], $elem->datos_originales_fila);
    }

    // ==========================================
    // F. PERSISTENCIA Y FILTRADO POR CÓDIGO
    // ==========================================

    /** 21. Persistencia, cast JSON y compatibilidad histórica NULL */
    public function test_21_persistencia_cast_json_y_compatibilidad_historica_null(): void
    {
        $solicitud = $this->createSolicitud();

        // Elemento histórico simulado sin códigos
        $elemHist = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 10,
            'cuip' => 'CUIPHIST1234567890',
            'resultado_prefiltro' => 'Descartado',
            'motivos_descarte' => ['Motivo histórico'],
            'codigos_descarte' => null,
        ]);

        $this->assertNull($elemHist->codigos_descarte);
        $this->assertIsArray($elemHist->motivosLabels());
        $this->assertEmpty($elemHist->motivosLabels());

        // Elemento con códigos persistidos
        $elemNuevo = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 11,
            'cuip' => 'CUIPNUEVO123456789',
            'resultado_prefiltro' => 'Descartado',
            'motivos_descarte' => ['El elemento no cuenta con una clave CUIP válida para su identificación institucional.'],
            'codigos_descarte' => [MotivoDescarte::CUIP_VACIO, MotivoDescarte::ARM_SIN_ASIGNACION],
        ]);

        $elemNuevo->refresh();
        $this->assertIsArray($elemNuevo->codigos_descarte);
        $this->assertSame(['CUIP_VACIO', 'ARM_SIN_ASIGNACION'], $elemNuevo->codigos_descarte);
        $this->assertSame(['CUIP no proporcionado', 'Sin asignación de armamento'], $elemNuevo->motivosLabels());
    }

    /** 22. Filtro por código de descarte en el controlador */
    public function test_22_filtro_por_codigo_de_descarte_en_controlador(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud();

        $elem1 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIPFIL11111111111',
            'resultado_prefiltro' => 'Descartado',
            'codigos_descarte' => [MotivoDescarte::EXP_ALTA_ACTIVA],
        ]);

        $elem2 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPFIL22222222222',
            'resultado_prefiltro' => 'Descartado',
            'codigos_descarte' => [MotivoDescarte::ARM_CORTA_CUPO_EXCEDIDO],
        ]);

        // Sin filtro: retorna ambos
        $respTodos = $this->get(route('credencializacion.detalle', $solicitud->id));
        $respTodos->assertOk();
        $elementosTodos = $respTodos->viewData('elementos');
        $this->assertCount(2, $elementosTodos);

        // Con filtro por EXP_ALTA_ACTIVA: retorna solo elem1
        $respAlta = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'motivo' => MotivoDescarte::EXP_ALTA_ACTIVA]));
        $respAlta->assertOk();
        $elementosAlta = $respAlta->viewData('elementos');
        $this->assertCount(1, $elementosAlta);
        $this->assertSame($elem1->id, $elementosAlta->first()->id);

        // Con filtro por ARM_CORTA_CUPO_EXCEDIDO: retorna solo elem2
        $respArma = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'motivo' => MotivoDescarte::ARM_CORTA_CUPO_EXCEDIDO]));
        $respArma->assertOk();
        $elementosArma = $respArma->viewData('elementos');
        $this->assertCount(1, $elementosArma);
        $this->assertSame($elem2->id, $elementosArma->first()->id);
    }

    /** 27. Códigos desconocidos en el filtro HTTP son rechazados */
    public function test_27_filtro_rechaza_codigos_desconocidos_con_error_de_validacion(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud();

        // Con código inexistente o inventado: debe rechazar con error de validación sobre 'motivo'
        $respInvalido = $this->get(route('credencializacion.detalle', [
            'solicitud' => $solicitud->id,
            'motivo' => 'CODIGO_INVENTADO_NO_EXISTE',
        ]));

        $respInvalido->assertSessionHasErrors(['motivo']);

        // Con código válido: status 200 OK
        $respValido = $this->get(route('credencializacion.detalle', [
            'solicitud' => $solicitud->id,
            'motivo' => MotivoDescarte::EXP_ALTA_ACTIVA,
        ]));

        $respValido->assertOk();
    }
}

