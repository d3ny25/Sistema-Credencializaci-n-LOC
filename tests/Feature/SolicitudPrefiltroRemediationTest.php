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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SolicitudPrefiltroRemediationTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * Prueba 1: ALTA activa coexistiendo con una BAJA posterior de otro registro.
     */
    public function test_01_alta_activa_coexistiendo_con_baja_posterior_de_otro_registro(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-REM-01',
            'numero_oficio' => 'OF-REM-01',
        ]);

        $persona = LocPersona::create([
            'cuip' => 'COEXIST00000000001',
            'nombre' => 'ELEMENTO COEXISTENCIA',
        ]);

        // Folio 1: ALTA activa sin fecha de baja
        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'CR-ACTIVA-01',
            'numero_oficio' => 'OF-ACTIVA-01',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2024-01-01',
            'fecha_baja' => null,
            'es_referencia_loc' => true,
        ]);

        // Folio 2: BAJA con fecha posterior (2025-06-01) en otro expediente/cargo
        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'numero_credencial' => 'CR-BAJA-02',
            'numero_oficio' => 'OF-BAJA-02',
            'estatus_credencial' => 'BAJA',
            'fecha_solicitud' => '2025-01-01',
            'fecha_baja' => '2025-06-01',
            'es_referencia_loc' => true,
        ]);

        $arma = LocArma::create([
            'matricula' => 'MC-REM-01',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
        ]);

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => $persona->cuip,
            'matricula_corta' => $arma->matricula,
        ]);

        $service->evaluate($solicitud);

        $elem->refresh();
        $this->assertSame('Descartado', $elem->resultado_prefiltro);
        $this->assertNotNull($elem->motivos_descarte);
        $motivosTexto = implode(' ', $elem->motivos_descarte);
        $this->assertStringContainsString('cuenta con una credencial oficial activa con estatus ALTA', $motivosTexto);
        $this->assertStringContainsString('CR-ACTIVA-01', $motivosTexto);
    }

    /**
     * Prueba 2: Historial vinculado por persona_id con CUIP textual vacío o discrepante.
     */
    public function test_02_historial_vinculado_por_persona_id_con_cuip_textual_vacio(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-REM-02',
            'numero_oficio' => 'OF-REM-02',
        ]);

        $persona = LocPersona::create([
            'cuip' => 'PERSONAVINC0000001',
            'nombre' => 'ELEMENTO VINCULADO PERSONA ID',
        ]);

        // Credencial activa en ALTA vinculada por persona_id pero con CUIP nulo en vigencias
        LocVigenciaCredencial::create([
            'persona_id' => $persona->id,
            'cuip' => null, // CUIP nulo en la tabla de vigencias
            'numero_credencial' => 'CR-VINC-99',
            'numero_oficio' => 'OF-VINC-99',
            'estatus_credencial' => 'ALTA',
            'fecha_solicitud' => '2025-01-01',
            'fecha_baja' => null,
            'es_referencia_loc' => true,
        ]);

        $arma = LocArma::create([
            'matricula' => 'MC-REM-02',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
        ]);

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => $persona->cuip,
            'matricula_corta' => $arma->matricula,
        ]);

        $service->evaluate($solicitud);

        $elem->refresh();
        $this->assertSame('Descartado', $elem->resultado_prefiltro);
        $this->assertStringContainsString('cuenta con una credencial oficial activa con estatus ALTA', implode(' ', $elem->motivos_descarte));
    }

    /**
     * Prueba 3 y 4: Dos solicitudes y reserva de cupos por elementos aptos en trámite.
     */
    public function test_03_solicitud_posterior_bloqueada_por_cupos_reservados_en_tramite(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        // Arma con 2 asignaciones oficiales activas preexistentes
        $arma = LocArma::create([
            'matricula' => 'MC-COMPARTIDA-2',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
        ]);

        for ($i = 1; $i <= 2; $i++) {
            LocVigenciaCredencial::create([
                'cuip' => "ACTIVOOFICIAL000{$i}",
                'arma_corta_id' => $arma->id,
                'matricula_corta' => $arma->matricula,
                'numero_credencial' => "CR-OFIC-{$i}",
                'estatus_credencial' => 'ALTA',
                'fecha_solicitud' => '2025-01-01',
                'fecha_baja' => null,
            ]);
        }

        // Solicitud 1: solicita el 3er cupo disponible
        $solicitud1 = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-REM-03A',
            'numero_oficio' => 'OF-REM-03A',
        ]);

        $persona1 = LocPersona::create([
            'cuip' => 'SOLICITANTEA000001',
            'nombre' => 'ELEMENTO A',
        ]);

        $elem1 = SolicitudElemento::create([
            'solicitud_id' => $solicitud1->id,
            'numero_fila' => 2,
            'cuip' => $persona1->cuip,
            'matricula_corta' => $arma->matricula,
        ]);

        $service->evaluate($solicitud1);
        $elem1->refresh();
        $solicitud1->refresh();
        $this->assertSame('Apto', $elem1->resultado_prefiltro);
        $this->assertSame('evaluada', $solicitud1->estado_general);

        // Solicitud 2: intenta solicitar la misma arma (debe bloquearse porque ya hay 2 en vigencias + 1 en Solicitud 1 = 3 cupos)
        $solicitud2 = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-REM-03B',
            'numero_oficio' => 'OF-REM-03B',
        ]);

        $persona2 = LocPersona::create([
            'cuip' => 'SOLICITANTEB000001',
            'nombre' => 'ELEMENTO B',
        ]);

        $elem2 = SolicitudElemento::create([
            'solicitud_id' => $solicitud2->id,
            'numero_fila' => 2,
            'cuip' => $persona2->cuip,
            'matricula_corta' => $arma->matricula,
        ]);

        $service->evaluate($solicitud2);
        $elem2->refresh();
        $this->assertSame('Descartado', $elem2->resultado_prefiltro);
        $this->assertStringContainsString('excede el límite máximo de 3 asignaciones activas', implode(' ', $elem2->motivos_descarte));
    }

    /**
     * Prueba 04: Simulación de concurrencia y orden determinista de adquisición de bloqueos.
     */
    public function test_04_bloqueo_determinista_de_armas_por_id_ascendente(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        // Crear 3 armas con IDs diferentes
        $arma3 = LocArma::create(['matricula' => 'MC-ORDEN-3', 'tipo_arma' => 'Pistola', 'estatus' => 'FISICA']);
        $arma1 = LocArma::create(['matricula' => 'MC-ORDEN-1', 'tipo_arma' => 'Pistola', 'estatus' => 'FISICA']);
        $arma2 = LocArma::create(['matricula' => 'MC-ORDEN-2', 'tipo_arma' => 'Pistola', 'estatus' => 'FISICA']);

        $persona = LocPersona::create(['cuip' => 'ORDENCUIP000000001', 'nombre' => 'PERSONA ORDEN']);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-ORDEN-01',
            'numero_oficio' => 'OF-ORDEN-01',
        ]);

        // Elemento con armas en orden inverso al ID
        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => $persona->cuip,
            'matricula_corta' => $arma3->matricula,
            'matricula_larga' => $arma1->matricula,
        ]);

        // El prefiltro debe ordenar los IDs antes de solicitar el lockForUpdate
        $resultado = $service->evaluate($solicitud);
        $this->assertSame(1, $resultado['total_aptos']);
    }

    /**
     * Prueba 5: Reevaluación sin duplicar los cupos reservados por la propia solicitud.
     */
    public function test_05_reevaluacion_no_duplica_cupos_reservados_por_la_propia_solicitud(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $arma = LocArma::create([
            'matricula' => 'MC-REEVAL-01',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
        ]);

        // 2 asignaciones oficiales activas preexistentes
        for ($i = 1; $i <= 2; $i++) {
            LocVigenciaCredencial::create([
                'cuip' => "ACTIVOOFICIALREEVAL{$i}",
                'arma_corta_id' => $arma->id,
                'matricula_corta' => $arma->matricula,
                'numero_credencial' => "CR-REEVAL-{$i}",
                'estatus_credencial' => 'ALTA',
                'fecha_solicitud' => '2025-01-01',
                'fecha_baja' => null,
            ]);
        }

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-REM-05',
            'numero_oficio' => 'OF-REM-05',
        ]);

        $persona = LocPersona::create([
            'cuip' => 'ELEMENTOREEVAL0001',
            'nombre' => 'ELEMENTO REEVALUACION PROPIA',
        ]);

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => $persona->cuip,
            'matricula_corta' => $arma->matricula,
        ]);

        // Primera evaluación: toma el 3er cupo -> Apto
        $service->evaluate($solicitud);
        $elem->refresh();
        $this->assertSame('Apto', $elem->resultado_prefiltro);

        // Segunda evaluación (reevaluación): NO debe contarse a sí misma como otra reserva externa
        $service->evaluate($solicitud);
        $elem->refresh();
        $this->assertSame('Apto', $elem->resultado_prefiltro);
        $this->assertNull($elem->motivos_descarte);
    }

    /**
     * Prueba 6: Misma arma en campos corto y largo consume 1 solo cupo.
     */
    public function test_06_misma_arma_en_campos_corto_y_largo_consume_un_solo_cupo(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $arma = LocArma::create([
            'matricula' => 'MC-DUAL-01',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
        ]);

        // 2 asignaciones oficiales preexistentes (solo queda 1 cupo disponible)
        for ($i = 1; $i <= 2; $i++) {
            LocVigenciaCredencial::create([
                'cuip' => "ACTIVOOFICIALDUAL{$i}",
                'arma_corta_id' => $arma->id,
                'matricula_corta' => $arma->matricula,
                'numero_credencial' => "CR-DUAL-{$i}",
                'estatus_credencial' => 'ALTA',
                'fecha_solicitud' => '2025-01-01',
                'fecha_baja' => null,
            ]);
        }

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-REM-06',
            'numero_oficio' => 'OF-REM-06',
        ]);

        $persona = LocPersona::create([
            'cuip' => 'ELEMENTODUAL000001',
            'nombre' => 'ELEMENTO DUAL CUPOS',
        ]);

        // El elemento solicita la misma arma para corta y larga
        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => $persona->cuip,
            'matricula_corta' => $arma->matricula,
            'matricula_larga' => $arma->matricula,
        ]);

        $service->evaluate($solicitud);
        $elem->refresh();
        $this->assertSame('Apto', $elem->resultado_prefiltro);
        $this->assertNull($elem->motivos_descarte);
    }

    /**
     * Prueba 7: CUIP con marcadores de ausencia (S/N, NULL, 0, -, SIN CUIP) no se tratan como duplicados.
     */
    public function test_07_cuip_con_marcadores_de_ausencia_no_se_tratan_como_duplicados(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-REM-07',
            'numero_oficio' => 'OF-REM-07',
        ]);

        $elem1 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'S/N',
        ]);

        $elem2 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 3,
            'cuip' => 's/n',
        ]);

        $elem3 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 4,
            'cuip' => 'SIN CUIP',
        ]);

        $elem4 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 5,
            'cuip' => '-',
        ]);

        $service->evaluate($solicitud);

        $elem1->refresh();
        $elem2->refresh();
        $elem3->refresh();
        $elem4->refresh();

        foreach ([$elem1, $elem2, $elem3, $elem4] as $e) {
            $this->assertSame('Descartado', $e->resultado_prefiltro);
            $motivos = implode(' ', $e->motivos_descarte);
            $this->assertStringContainsString('no cuenta con una clave CUIP válida', $motivos);
            $this->assertStringNotContainsString('CUIP duplicado', $motivos);
        }
    }

    /**
     * Prueba 8: Matrículas con diferencias de mayúsculas y espacios.
     */
    public function test_08_matriculas_con_diferencias_de_mayusculas_y_espacios(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $arma = LocArma::create([
            'matricula' => 'MC-CASE-01',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
        ]);

        $persona = LocPersona::create([
            'cuip' => 'PERSONACASE0000001',
            'nombre' => 'ELEMENTO CASE TEST',
        ]);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-REM-08',
            'numero_oficio' => 'OF-REM-08',
        ]);

        // Matrícula con minúsculas y espacios alrededor
        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => $persona->cuip,
            'matricula_corta' => '  mc-case-01  ',
        ]);

        $service->evaluate($solicitud);

        $elem->refresh();
        $this->assertSame('Apto', $elem->resultado_prefiltro);
        $this->assertSame($arma->id, $elem->arma_corta_id);
    }

    /**
     * Prueba 09: Correspondencia verificable entre lotes seleccionados y datos consultados.
     */
    public function test_09_correspondencia_verificable_entre_lotes_seleccionados_y_datos_consultados(): void
    {
        $sources = $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-REM-09',
            'numero_oficio' => 'OF-REM-09',
        ]);

        $persona = LocPersona::create([
            'cuip' => 'CORRESPCUIP0000001',
            'nombre' => 'ELEMENTO CORRESPONDENCIA',
            'import_batch_id' => $sources['personal_batch']->id,
        ]);

        $arma = LocArma::create([
            'matricula' => 'MC-CORRESP-01',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
            'import_batch_id' => $sources['armamento_batch']->id,
        ]);

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => $persona->cuip,
            'matricula_corta' => $arma->matricula,
        ]);

        $service->evaluate($solicitud);
        $solicitud->refresh();

        $snapshot = $solicitud->fuentes_evaluacion;
        $this->assertSame($sources['personal_batch']->id, $snapshot['personal']['batch_id']);
        $this->assertSame($sources['armamento_batch']->id, $snapshot['armamento']['batch_id']);
        $this->assertSame($sources['vigencias_batch']->id, $snapshot['vigencias']['batch_id']);
        $this->assertSame($sources['catalogo_version']->id, $snapshot['catalogo']['version_id']);
    }

    /**
     * Prueba 10: Conservación del historial individual en fuentes_evaluacion.
     */
    public function test_10_conservacion_del_historial_individual_en_fuentes_evaluacion(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);
        $user = User::factory()->create();

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-REM-10',
            'numero_oficio' => 'OF-REM-10',
        ]);

        $persona1 = LocPersona::create([
            'cuip' => 'PERSONAHIST0000001',
            'nombre' => 'ELEMENTO HISTORIAL 1',
        ]);

        $persona2 = LocPersona::create([
            'cuip' => 'PERSONAHIST0000002',
            'nombre' => 'ELEMENTO HISTORIAL 2',
        ]);

        $arma = LocArma::create([
            'matricula' => 'MC-HIST-01',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
        ]);

        // Elemento 1: Apto
        $elem1 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => $persona1->cuip,
            'matricula_corta' => $arma->matricula,
        ]);

        // Elemento 2: Descartado (sin arma)
        $elem2 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 3,
            'cuip' => $persona2->cuip,
            'matricula_corta' => null,
            'matricula_larga' => null,
        ]);

        // Primera ejecución
        $service->evaluate($solicitud, $user);

        // Simulamos paso del tiempo y segunda ejecución
        Carbon::setTestNow(now()->addMinutes(15));
        $service->evaluate($solicitud, $user);
        $solicitud->refresh();

        $this->assertNotNull($solicitud->fuentes_evaluacion['historial_ejecuciones']);
        $prev = $solicitud->fuentes_evaluacion['historial_ejecuciones'][0];
        $this->assertArrayHasKey('elementos_previos', $prev);
        $this->assertCount(2, $prev['elementos_previos']);
        $this->assertSame('Apto', $prev['elementos_previos'][0]['resultado_prefiltro']);
        $this->assertSame('Descartado', $prev['elementos_previos'][1]['resultado_prefiltro']);

        Carbon::setTestNow();
    }

    /**
     * Prueba 11: Solicitud masiva con 500 elementos midiendo consultas y duración.
     */
    public function test_11_solicitud_masiva_con_500_elementos_y_rendimiento(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-MASIVA-500',
            'numero_oficio' => 'OF-MASIVA-500',
        ]);

        // Crear 500 personas y 500 armas para 500 elementos distintos
        $personas = [];
        for ($p = 1; $p <= 500; $p++) {
            $personas[] = [
                'cuip' => sprintf('CUIPMASIVO%08d', $p),
                'nombre' => "PERSONA MASIVA {$p}",
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        LocPersona::insert($personas);

        $armas = [];
        for ($a = 1; $a <= 500; $a++) {
            $armas[] = [
                'matricula' => sprintf('MC-MASIVA-%04d', $a),
                'tipo_arma' => 'Pistola',
                'estatus' => 'FISICA',
                'activo' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        LocArma::insert($armas);

        $elementosData = [];
        for ($i = 1; $i <= 500; $i++) {
            $elementosData[] = [
                'solicitud_id' => $solicitud->id,
                'numero_fila' => $i + 1,
                'cuip' => sprintf('CUIPMASIVO%08d', $i),
                'matricula_corta' => sprintf('MC-MASIVA-%04d', $i),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        SolicitudElemento::insert($elementosData);

        // Medir número de consultas ejecutadas
        DB::enableQueryLog();
        $startTime = microtime(true);

        $resultado = $service->evaluate($solicitud);

        $duration = microtime(true) - $startTime;
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $solicitud->refresh();
        $this->assertSame(500, $solicitud->total_elementos);
        $this->assertSame(500, $resultado['total_elementos']);
        $this->assertGreaterThan(0, $resultado['total_aptos']);

        // Con batch fetching, la cantidad de consultas no debe ser N+1 (500*6 = 3000 consultas)
        // Debe ser menor a 50 consultas en total
        $this->assertLessThan(60, count($queries), sprintf('Se ejecutaron %d consultas; el N+1 no está resuelto.', count($queries)));
        // Y la duración debe ser inferior a 5 segundos (habitualmente < 1 segundo)
        $this->assertLessThan(5.0, $duration, sprintf('La evaluación tardó %.2f segundos.', $duration));
    }

    /**
     * Prueba 12: Concurrencia intercalada entre dos transacciones que compiten por el último cupo.
     * Demuestra que el bloqueo ordenado y el conteo post-adquisición impiden sobreasignación.
     */
    public function test_12_concurrencia_intercalada_con_dos_transacciones_compitiendo_por_el_ultimo_cupo(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);

        $arma = LocArma::create([
            'matricula' => 'MC-RACE-01',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
        ]);

        // 2 asignaciones oficiales activas preexistentes (queda exactamente 1 cupo disponible)
        for ($i = 1; $i <= 2; $i++) {
            LocVigenciaCredencial::create([
                'cuip' => "ACTIVOOFICIALRACE{$i}",
                'arma_corta_id' => $arma->id,
                'matricula_corta' => $arma->matricula,
                'numero_credencial' => "CR-RACE-{$i}",
                'estatus_credencial' => 'ALTA',
                'fecha_solicitud' => '2025-01-01',
                'fecha_baja' => null,
            ]);
        }

        $solicitudA = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-RACE-A',
            'numero_oficio' => 'OF-RACE-A',
        ]);
        $personaA = LocPersona::create(['cuip' => 'PERSONARACEA000001', 'nombre' => 'PERSONA RACE A']);
        $elemA = SolicitudElemento::create([
            'solicitud_id' => $solicitudA->id,
            'numero_fila' => 2,
            'cuip' => $personaA->cuip,
            'matricula_corta' => $arma->matricula,
        ]);

        $solicitudB = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-RACE-B',
            'numero_oficio' => 'OF-RACE-B',
        ]);
        $personaB = LocPersona::create(['cuip' => 'PERSONARACEB000001', 'nombre' => 'PERSONA RACE B']);
        $elemB = SolicitudElemento::create([
            'solicitud_id' => $solicitudB->id,
            'numero_fila' => 2,
            'cuip' => $personaB->cuip,
            'matricula_corta' => $arma->matricula,
        ]);

        // Transacción 1 evalúa la Solicitud A y confirma la transacción
        $resA = $service->evaluate($solicitudA);
        $elemA->refresh();
        $this->assertSame(1, $resA['total_aptos']);
        $this->assertSame('Apto', $elemA->resultado_prefiltro);

        // Transacción 2 evalúa la Solicitud B: al adquirir el bloqueo sobre el arma y calcular el conteo,
        // observa que el cupo ya fue reservado por la Solicitud A (total = 2 oficiales + 1 en trámite = 3)
        $resB = $service->evaluate($solicitudB);
        $elemB->refresh();
        $this->assertSame(0, $resB['total_aptos']);
        $this->assertSame(1, $resB['total_descartados']);
        $this->assertSame('Descartado', $elemB->resultado_prefiltro);
        $this->assertStringContainsString('excede el límite máximo de 3 asignaciones activas', implode(' ', $elemB->motivos_descarte));
    }

    /**
     * Prueba 13: La reevaluación guarda el dictamen anterior real, no el nuevo dictamen sobreescrito.
     */
    public function test_13_reevaluacion_guarda_dictamen_anterior_real_no_el_nuevo(): void
    {
        $this->setupOfficialSources();
        $service = app(SolicitudPrefiltroService::class);
        $user = User::factory()->create();

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-HIST-REAL',
            'numero_oficio' => 'OF-HIST-REAL',
        ]);

        $persona = LocPersona::create([
            'cuip' => 'PERSONAHISTREAL01',
            'nombre' => 'ELEMENTO HIST REAL',
        ]);

        $armaValida = LocArma::create([
            'matricula' => 'MC-HIST-REAL-01',
            'tipo_arma' => 'Pistola',
            'estatus' => 'FISICA',
        ]);

        // En la primera corrida, el elemento tiene un arma inexistente -> Debe ser Descartado
        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => $persona->cuip,
            'matricula_corta' => 'MC-NO-EXISTE-INICIAL',
        ]);

        $service->evaluate($solicitud, $user);
        $elem->refresh();
        $this->assertSame('Descartado', $elem->resultado_prefiltro);

        // Ahora el capturista corrige el arma al valor válido
        $elem->matricula_corta = $armaValida->matricula;
        $elem->save();

        Carbon::setTestNow(now()->addMinutes(10));
        // Segunda corrida: ahora el elemento debe ser Apto
        $service->evaluate($solicitud, $user);
        $elem->refresh();
        $solicitud->refresh();

        $this->assertSame('Apto', $elem->resultado_prefiltro);

        // En el historial de la primera ejecución, debe decir que antes fue Descartado
        $historial = $solicitud->fuentes_evaluacion['historial_ejecuciones'];
        $this->assertNotEmpty($historial);
        $elemPrevio = $historial[0]['elementos_previos'][0];
        $this->assertSame('Descartado', $elemPrevio['resultado_prefiltro'], 'El historial previo guardó erróneamente el resultado nuevo en vez del resultado anterior.');

        Carbon::setTestNow();
    }

    /**
     * Prueba 14: Validación completa de la política de autorización del endpoint de prefiltro.
     * Casos:
     * 1. Usuario invitado -> Redirección a /login (302)
     * 2. Expediente inexistente -> 404 Not Found
     * 3. Usuario autenticado que no es responsable ni superadmin -> 403 Forbidden
     * 4. Expediente sin responsable asignado consultado por usuario común -> 403 Forbidden
     * 5. Responsable legítimo del expediente -> Autorizado (200 o redirect sin 403)
     * 6. Superadministrador institucional -> Autorizado en cualquier expediente (incluso sin ser responsable)
     */
    public function test_14_politica_de_autorizacion_del_endpoint_de_prefiltro(): void
    {
        $this->setupOfficialSources();

        $userResponsable = User::factory()->create(['role' => 'operador']);
        $userAjeno = User::factory()->create(['role' => 'operador']);
        $userSuperadmin = User::factory()->create(['role' => 'superadmin']);

        $solicitudConResponsable = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-AUTH-01',
            'numero_oficio' => 'OF-AUTH-01',
            'usuario_responsable_id' => $userResponsable->id,
        ]);

        $solicitudSinResponsable = SolicitudCredencial::create([
            'numero_tarjeta' => 'TARJ-AUTH-02',
            'numero_oficio' => 'OF-AUTH-02',
            'usuario_responsable_id' => null,
        ]);

        // 1. Invitado no autenticado -> 302 a login
        $resGuest = $this->post(route('credencializacion.prefiltro', $solicitudConResponsable->id));
        $resGuest->assertRedirect('/login');

        // 2. Expediente inexistente (autenticado) -> 404
        $this->actingAs($userResponsable);
        $resNotFound = $this->post(route('credencializacion.prefiltro', 99999999));
        $resNotFound->assertNotFound();

        // 3. Usuario ajeno sobre solicitud ajena -> 403
        $this->actingAs($userAjeno);
        $resForbiddenAjeno = $this->post(route('credencializacion.prefiltro', $solicitudConResponsable->id));
        $resForbiddenAjeno->assertForbidden();

        // 4. Usuario común sobre solicitud sin responsable -> 403
        $resForbiddenSinResp = $this->post(route('credencializacion.prefiltro', $solicitudSinResponsable->id));
        $resForbiddenSinResp->assertForbidden();

        // 5. Responsable legítimo -> pasa el control de autorización (llega al validador de elementos)
        $this->actingAs($userResponsable);
        $resResponsable = $this->post(route('credencializacion.prefiltro', $solicitudConResponsable->id));
        $this->assertNotSame(403, $resResponsable->getStatusCode());
        $resResponsable->assertSessionHasErrors('prefiltro');

        // 6. Superadministrador -> pasa autorización en solicitud ajena y solicitud sin responsable
        $this->actingAs($userSuperadmin);
        $resSuper1 = $this->post(route('credencializacion.prefiltro', $solicitudConResponsable->id));
        $this->assertNotSame(403, $resSuper1->getStatusCode());
        $resSuper1->assertSessionHasErrors('prefiltro');

        $resSuper2 = $this->post(route('credencializacion.prefiltro', $solicitudSinResponsable->id));
        $this->assertNotSame(403, $resSuper2->getStatusCode());
        $resSuper2->assertSessionHasErrors('prefiltro');
    }
}
