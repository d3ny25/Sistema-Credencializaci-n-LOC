<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\PrefiltroSourcesMissingException;
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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SolicitudPrefiltroEnginePhaseTwoThreeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Prepara el entorno oficial con lotes confirmados y versión de catálogo vigente.
     *
     * @return array{
     *     personal_batch: LocImportBatch,
     *     armamento_batch: LocImportBatch,
     *     vigencias_batch: LocImportBatch,
     *     catalogo_version: LocCatalogoVersion
     * }
     */
    private function setupOfficialSources(): array
    {
        $user = User::factory()->create();

        $personalBatch = LocImportBatch::create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'Personal_Oficial_2026.xlsx',
            'estado' => 'completado',
            'fecha_confirmacion' => Carbon::now()->subDays(5),
            'usuario_id' => $user->id,
            'total_leidas' => 100,
            'total_insertadas' => 100,
        ]);

        $armamentoBatch = LocImportBatch::create([
            'tipo_fuente' => 'armamento_loc',
            'nombre_archivo' => 'Armamento_Oficial_2026.xlsx',
            'estado' => 'completado',
            'fecha_confirmacion' => Carbon::now()->subDays(4),
            'usuario_id' => $user->id,
            'total_leidas' => 100,
            'total_insertadas' => 100,
        ]);

        $vigenciasBatch = LocImportBatch::create([
            'tipo_fuente' => LocCredentialsXlsImporter::SOURCE_TYPE,
            'nombre_archivo' => 'Vigencias_Oficiales_2026.xlsx',
            'estado' => 'completado',
            'fecha_confirmacion' => Carbon::now()->subDays(3),
            'usuario_id' => $user->id,
            'total_leidas' => 100,
            'total_insertadas' => 100,
        ]);

        $catalogoBatch = LocImportBatch::create([
            'tipo_fuente' => 'catalogo_organizacional',
            'nombre_archivo' => 'Catalogo_Oficial_2026.xlsx',
            'estado' => 'completado',
            'fecha_confirmacion' => Carbon::now()->subDays(2),
            'usuario_id' => $user->id,
            'total_leidas' => 50,
            'total_insertadas' => 50,
        ]);

        $catalogoVersion = LocCatalogoVersion::create([
            'import_batch_id' => $catalogoBatch->id,
            'version_numero' => 1,
            'es_vigente' => true,
            'total_registros' => 50,
            'archivo_nombre' => 'Catalogo_Oficial_2026.xlsx',
            'usuario_id' => $user->id,
            'fecha_activacion' => Carbon::now()->subDays(2),
        ]);

        return [
            'personal_batch' => $personalBatch,
            'armamento_batch' => $armamentoBatch,
            'vigencias_batch' => $vigenciasBatch,
            'catalogo_version' => $catalogoVersion,
        ];
    }

    public function test_01_cuip_vacio_o_invalido(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-TEST-01',
            'numero_oficio' => 'OF-TEST-01',
        ]);

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => null, // CUIP vacío
            'matricula_corta' => 'MC-01',
        ]);

        LocArma::create([
            'matricula' => 'MC-01',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
        ]);

        $service->evaluate($solicitud);

        $elem->refresh();
        $this->assertSame('Descartado', $elem->resultado_prefiltro);
        $this->assertNull($elem->persona_id);
        $this->assertIsArray($elem->motivos_descarte);
        $this->assertStringContainsString('no cuenta con una clave CUIP válida', $elem->motivos_descarte[0]);
    }

    public function test_02_cuip_que_no_existe_en_loc(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-TEST-02',
            'numero_oficio' => 'OF-TEST-02',
        ]);

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'INEXISTENTE00000000',
            'matricula_corta' => 'MC-02',
        ]);

        LocArma::create([
            'matricula' => 'MC-02',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
        ]);

        $service->evaluate($solicitud);

        $elem->refresh();
        $this->assertSame('Descartado', $elem->resultado_prefiltro);
        $this->assertNull($elem->persona_id);
        $this->assertIsArray($elem->motivos_descarte);
        $this->assertStringContainsString('no fue localizado en el padrón oficial', $elem->motivos_descarte[0]);
    }

    public function test_03_todas_las_filas_de_un_cuip_duplicado_descartadas(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-TEST-03',
            'numero_oficio' => 'OF-TEST-03',
        ]);

        $persona = LocPersona::create([
            'cuip' => 'DUPCUIP00000000001',
            'nombre' => 'JUAN PEREZ DUP',
        ]);

        $arma1 = LocArma::create([
            'matricula' => 'MC-DUP-01',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
        ]);

        $arma2 = LocArma::create([
            'matricula' => 'MC-DUP-02',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
        ]);

        $elem1 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'DUPCUIP00000000001',
            'matricula_corta' => 'MC-DUP-01',
        ]);

        $elem2 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 5,
            'cuip' => 'DUPCUIP00000000001',
            'matricula_corta' => 'MC-DUP-02',
        ]);

        $service->evaluate($solicitud);

        $elem1->refresh();
        $elem2->refresh();

        // Ambas filas conservadas pero descartadas
        $this->assertSame('Descartado', $elem1->resultado_prefiltro);
        $this->assertSame('Descartado', $elem2->resultado_prefiltro);
        $this->assertStringContainsString('presente en las filas 2, 5', implode(' ', $elem1->motivos_descarte));
        $this->assertStringContainsString('presente en las filas 2, 5', implode(' ', $elem2->motivos_descarte));
    }

    public function test_04_estatus_alta(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-TEST-04',
            'numero_oficio' => 'OF-TEST-04',
        ]);

        $persona = LocPersona::create([
            'cuip' => 'ALTACUIP0000000001',
            'nombre' => 'ELEMENTO ACTIVO ALTA',
        ]);

        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'CR-ALTA-99',
            'numero_oficio' => 'OF-ALTA-99',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2025-01-01',
            'fecha_baja' => null,
            'es_referencia_loc' => true,
        ]);

        LocArma::create([
            'matricula' => 'MC-04',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
        ]);

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'ALTACUIP0000000001',
            'matricula_corta' => 'MC-04',
        ]);

        $service->evaluate($solicitud);

        $elem->refresh();
        $this->assertSame('Descartado', $elem->resultado_prefiltro);
        $this->assertStringContainsString('cuenta con una credencial oficial activa con estatus ALTA', implode(' ', $elem->motivos_descarte));
    }

    public function test_05_estatus_situacion_especial(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-TEST-05',
            'numero_oficio' => 'OF-TEST-05',
        ]);

        $persona = LocPersona::create([
            'cuip' => 'ESPECIALCUIP000001',
            'nombre' => 'ELEMENTO EN SITUACION ESPECIAL',
        ]);

        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'CR-ESP-01',
            'estatus_credencial' => 'SITUACION ESPECIAL',
            'motivo_situacion_especial' => 'SUSPENSION TEMPORAL DE FUNCIONES',
            'fecha_solicitud' => '2025-06-01',
            'es_referencia_loc' => true,
        ]);

        LocArma::create([
            'matricula' => 'MC-05',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
        ]);

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'ESPECIALCUIP000001',
            'matricula_corta' => 'MC-05',
        ]);

        $service->evaluate($solicitud);

        $elem->refresh();
        $this->assertSame('Descartado', $elem->resultado_prefiltro);
        $this->assertStringContainsString('SITUACIÓN ESPECIAL', implode(' ', $elem->motivos_descarte));
        $this->assertStringContainsString('SUSPENSION TEMPORAL DE FUNCIONES', implode(' ', $elem->motivos_descarte));
    }

    public function test_06_estatus_cancelacion(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-TEST-06',
            'numero_oficio' => 'OF-TEST-06',
        ]);

        $persona = LocPersona::create([
            'cuip' => 'CANCELCUIP00000001',
            'nombre' => 'ELEMENTO CANCELADO',
        ]);

        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'CR-CAN-01',
            'estatus_credencial' => 'CANCELACION',
            'fecha_solicitud' => '2025-08-01',
            'es_referencia_loc' => true,
        ]);

        LocArma::create([
            'matricula' => 'MC-06',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
        ]);

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CANCELCUIP00000001',
            'matricula_corta' => 'MC-06',
        ]);

        $service->evaluate($solicitud);

        $elem->refresh();
        $this->assertSame('Descartado', $elem->resultado_prefiltro);
        $this->assertStringContainsString('CANCELACIÓN', implode(' ', $elem->motivos_descarte));
        $this->assertStringContainsString('resolución administrativa previa', implode(' ', $elem->motivos_descarte));
    }

    public function test_07_estatus_baja_permite_tramite_apto(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-TEST-07',
            'numero_oficio' => 'OF-TEST-07',
        ]);

        $persona = LocPersona::create([
            'cuip' => 'BAJACUIP0000000001',
            'nombre' => 'ELEMENTO CON BAJA PREVIA',
        ]);

        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'CR-BAJA-01',
            'estatus_credencial' => 'BAJA',
            'fecha_baja' => '2025-01-15',
            'es_referencia_loc' => true,
        ]);

        LocArma::create([
            'matricula' => 'MC-07',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
        ]);

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'BAJACUIP0000000001',
            'matricula_corta' => 'MC-07',
        ]);

        $service->evaluate($solicitud);

        $elem->refresh();
        $this->assertSame('Apto', $elem->resultado_prefiltro);
        $this->assertNull($elem->motivos_descarte);
        $this->assertSame('apto', $elem->estado_tramite);
    }

    public function test_08_arma_inexistente(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-TEST-08',
            'numero_oficio' => 'OF-TEST-08',
        ]);

        $persona = LocPersona::create([
            'cuip' => 'ARMAFAILCUIP000001',
            'nombre' => 'ELEMENTO ARMA INEXISTENTE',
        ]);

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'ARMAFAILCUIP000001',
            'matricula_corta' => 'MC-NO-EXISTE',
        ]);

        $service->evaluate($solicitud);

        $elem->refresh();
        $this->assertSame('Descartado', $elem->resultado_prefiltro);
        $this->assertNull($elem->arma_corta_id);
        $this->assertStringContainsString("El arma corta con matrícula 'MC-NO-EXISTE' no fue localizada", implode(' ', $elem->motivos_descarte));
    }

    public function test_09_arma_con_estatus_distinto_de_fisica(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-TEST-09',
            'numero_oficio' => 'OF-TEST-09',
        ]);

        $persona = LocPersona::create([
            'cuip' => 'ARMAEXTRAVACUIP001',
            'nombre' => 'ELEMENTO ARMA EXTRAVIADA',
        ]);

        LocArma::create([
            'matricula' => 'MC-EXTRAVIADA',
            'tipo_arma' => 'Pistola',
            'estatus' => 'EXTRAVIADA', // No es FISICA
        ]);

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'ARMAEXTRAVACUIP001',
            'matricula_corta' => 'MC-EXTRAVIADA',
        ]);

        $service->evaluate($solicitud);

        $elem->refresh();
        $this->assertSame('Descartado', $elem->resultado_prefiltro);
        $this->assertStringContainsString("tiene estatus 'EXTRAVIADA' en el padrón oficial", implode(' ', $elem->motivos_descarte));
    }

    public function test_10_elemento_sin_arma(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-TEST-10',
            'numero_oficio' => 'OF-TEST-10',
        ]);

        $persona = LocPersona::create([
            'cuip' => 'SINARMACUIP0000001',
            'nombre' => 'ELEMENTO SIN ARMA',
        ]);

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'SINARMACUIP0000001',
            'matricula_corta' => null,
            'matricula_larga' => null,
        ]);

        $service->evaluate($solicitud);

        $elem->refresh();
        $this->assertSame('Descartado', $elem->resultado_prefiltro);
        $this->assertStringContainsString('no cuenta con ninguna matrícula de arma asignada', implode(' ', $elem->motivos_descarte));
    }

    public function test_11_elemento_con_dos_armas_y_una_invalida(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-TEST-11',
            'numero_oficio' => 'OF-TEST-11',
        ]);

        $persona = LocPersona::create([
            'cuip' => 'DUALCUIP0000000001',
            'nombre' => 'ELEMENTO DOS ARMAS',
        ]);

        // Corta válida
        LocArma::create([
            'matricula' => 'MC-VALIDA',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
        ]);

        // Larga inválida (en reparación)
        LocArma::create([
            'matricula' => 'ML-REPARACION',
            'tipo_arma' => 'Fusil',
            'estatus' => 'EN_REPARACION',
        ]);

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'DUALCUIP0000000001',
            'matricula_corta' => 'MC-VALIDA',
            'matricula_larga' => 'ML-REPARACION',
        ]);

        $service->evaluate($solicitud);

        $elem->refresh();
        $this->assertSame('Descartado', $elem->resultado_prefiltro);
        $this->assertNotNull($elem->arma_corta_id);
        $this->assertNotNull($elem->arma_larga_id);
        $this->assertStringContainsString("tiene estatus 'EN_REPARACION'", implode(' ', $elem->motivos_descarte));
    }

    public function test_12_limite_de_tres_asignaciones_activas(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-TEST-12',
            'numero_oficio' => 'OF-TEST-12',
        ]);

        $armaSaturada = LocArma::create([
            'matricula' => 'MC-COMPARTIDA-3',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
        ]);

        // Crear 3 credenciales activas preexistentes en vigencias con esta arma
        for ($i = 1; $i <= 3; $i++) {
            LocVigenciaCredencial::create([
                'cuip' => "ACTIVO00000000000{$i}",
                'arma_corta_id' => $armaSaturada->id,
                'matricula_corta' => $armaSaturada->matricula,
                'numero_credencial' => "CRED-ACTIVA-{$i}",
                'estatus_credencial' => 'ALTA',
                'fecha_solicitud' => '2025-01-01',
                'fecha_baja' => null,
            ]);
        }

        $persona = LocPersona::create([
            'cuip' => 'SOLICITANTE0000001',
            'nombre' => 'ELEMENTO INTENTA CUARTO CUPO',
        ]);

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'SOLICITANTE0000001',
            'matricula_corta' => 'MC-COMPARTIDA-3',
        ]);

        $service->evaluate($solicitud);

        $elem->refresh();
        $this->assertSame('Descartado', $elem->resultado_prefiltro);
        $this->assertStringContainsString('excede el límite máximo de 3 asignaciones activas con estatus ALTA', implode(' ', $elem->motivos_descarte));
    }

    public function test_13_acumulacion_de_multiples_motivos_de_descarte(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-TEST-13',
            'numero_oficio' => 'OF-TEST-13',
        ]);

        // CUIP inexistente + arma corta inexistente + arma larga no FISICA
        LocArma::create([
            'matricula' => 'ML-DEPOSITO',
            'tipo_arma' => 'Escopeta',
            'estatus' => 'DEPOSITO',
        ]);

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'NOEXISTEPERSONAL99',
            'matricula_corta' => 'MC-INEXISTENTE',
            'matricula_larga' => 'ML-DEPOSITO',
        ]);

        $service->evaluate($solicitud);

        $elem->refresh();
        $this->assertSame('Descartado', $elem->resultado_prefiltro);
        $this->assertCount(3, $elem->motivos_descarte);
        $this->assertStringContainsString('no fue localizado en el padrón oficial vigente de personal', $elem->motivos_descarte[0]);
        $this->assertStringContainsString("El arma corta con matrícula 'MC-INEXISTENTE' no fue localizada", $elem->motivos_descarte[1]);
        $this->assertStringContainsString("tiene estatus 'DEPOSITO' en el padrón oficial", $elem->motivos_descarte[2]);
    }

    public function test_14_seleccion_correcta_de_lotes_y_version_de_catalogo(): void
    {
        $sources = $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        // Crear lotes más nuevos pero sin confirmación o no completados
        LocImportBatch::create([
            'tipo_fuente' => 'personal_autorizado_loc',
            'nombre_archivo' => 'Personal_Borrador_Incompleto.xlsx',
            'estado' => 'en_revision',
            'fecha_confirmacion' => null,
        ]);

        // Crear un lote de catálogo en borrador y una versión de catálogo posterior pero NO vigente
        $batchV2 = LocImportBatch::create([
            'tipo_fuente' => 'catalogo_organizacional',
            'nombre_archivo' => 'Catalogo_V2_Borrador.xlsx',
            'estado' => 'en_revision',
        ]);

        LocCatalogoVersion::create([
            'import_batch_id' => $batchV2->id,
            'version_numero' => 2,
            'es_vigente' => false,
            'archivo_nombre' => 'Catalogo_V2_Borrador.xlsx',
        ]);

        $resolved = $service->resolveOfficialSources();

        $this->assertSame($sources['personal_batch']->id, $resolved['personal_batch']->id);
        $this->assertSame($sources['catalogo_version']->id, $resolved['catalogo_version']->id);
        $this->assertTrue($resolved['catalogo_version']->es_vigente);
    }

    public function test_15_ausencia_de_fuentes_necesarias_impide_ejecucion(): void
    {
        // No llamamos a setupOfficialSources -> base vacía de fuentes
        $service = app(SolicitudPrefiltroService::class);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-TEST-15',
            'numero_oficio' => 'OF-TEST-15',
        ]);

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIP00000000000001',
        ]);

        $this->expectException(PrefiltroSourcesMissingException::class);
        $service->evaluate($solicitud);
    }

    public function test_16_consistencia_de_totales_y_rollback_ante_excepciones(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-TEST-16',
            'numero_oficio' => 'OF-TEST-16',
        ]);

        $persona = LocPersona::create([
            'cuip' => 'ROLLBACKCUIP000001',
            'nombre' => 'ELEMENTO PRUEBA TOTALES',
        ]);

        LocArma::create([
            'matricula' => 'MC-16',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
        ]);

        $elem1 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'ROLLBACKCUIP000001',
            'matricula_corta' => 'MC-16',
        ]);

        $elem2 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 3,
            'cuip' => null, // Descartado
        ]);

        $result = $service->evaluate($solicitud);

        $solicitud->refresh();
        $this->assertSame(2, $solicitud->total_elementos);
        $this->assertSame(1, $solicitud->total_aptos);
        $this->assertSame(1, $solicitud->total_descartados);
        $this->assertSame($solicitud->total_elementos, $solicitud->total_aptos + $solicitud->total_descartados);
        $this->assertSame('evaluada', $solicitud->estado_general);
        $this->assertNotNull($solicitud->fecha_prefiltro);
    }

    public function test_17_proteccion_de_las_rutas(): void
    {
        $this->setupOfficialSources();

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-TEST-17',
            'numero_oficio' => 'OF-TEST-17',
        ]);

        // Intento como invitado sin autenticar
        $response = $this->post(route('credencializacion.prefiltro', $solicitud->id));
        $response->assertRedirect('/login');

        // Intento con usuario autenticado responsable
        $user = User::factory()->create();
        $solicitud->usuario_responsable_id = $user->id;
        $solicitud->save();
        $this->actingAs($user);

        // Sin elementos -> mensaje de error controlado
        $responseAuth = $this->post(route('credencializacion.prefiltro', $solicitud->id));
        $responseAuth->assertSessionHasErrors('prefiltro');
    }

    public function test_18_reejecucion_del_prefiltro_y_conservacion_del_historial(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);
        $user = User::factory()->create();

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-TEST-18',
            'numero_oficio' => 'OF-TEST-18',
        ]);

        $persona = LocPersona::create([
            'cuip' => 'HISTORIALCUIP00001',
            'nombre' => 'ELEMENTO REEJECUCION',
        ]);

        $arma = LocArma::create([
            'matricula' => 'MC-18',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
        ]);

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'HISTORIALCUIP00001',
            'matricula_corta' => 'MC-18',
        ]);

        // Primera ejecución
        $service->evaluate($solicitud, $user);
        $solicitud->refresh();
        $firstDate = $solicitud->fecha_prefiltro;

        // Simulamos paso del tiempo y segunda ejecución
        Carbon::setTestNow(now()->addMinutes(10));
        $service->evaluate($solicitud, $user);
        $solicitud->refresh();

        $this->assertNotNull($solicitud->fuentes_evaluacion['historial_ejecuciones']);
        $this->assertCount(1, $solicitud->fuentes_evaluacion['historial_ejecuciones']);
        $this->assertSame(1, $solicitud->fuentes_evaluacion['historial_ejecuciones'][0]['total_aptos']);
        Carbon::setTestNow();
    }

    public function test_19_regresion_de_importacion_alta_de_solicitudes_y_modal(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Verificamos que la pantalla de listado de credencialización renderice el botón y modal
        $response = $this->get(route('credencializacion'));
        $response->assertOk();
        $response->assertSee('Nueva solicitud');
        $response->assertSee('modalNuevaSolicitud');
        $response->assertSee('fixed inset-0 z-50 flex min-h-screen items-center justify-center', false);
    }
}
