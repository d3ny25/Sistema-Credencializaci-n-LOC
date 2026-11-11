<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LocArma;
use App\Models\LocVigenciaCredencial;
use App\Services\LocCredentialsWeaponsReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocCredentialsWeaponsReconcilerTest extends TestCase
{
    use RefreshDatabase;

    private LocCredentialsWeaponsReconciler $reconciler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reconciler = new LocCredentialsWeaponsReconciler();
    }

    private function createArma(string $matricula, string $tipo = 'CORTA', string $clase = 'PISTOLA'): LocArma
    {
        return LocArma::create([
            'matricula' => $matricula,
            'tipo_arma' => $tipo,
            'clase_arma' => $clase,
            'marca' => 'GLOCK',
            'modelo' => '17',
            'calibre' => '9MM',
            'activo' => true,
        ]);
    }

    private function createVigencia(array $attributes = []): LocVigenciaCredencial
    {
        return LocVigenciaCredencial::create(array_merge([
            'cuip' => 'TEST000000HDFRR00',
            'tipo_credencial' => 'CREDENCIAL DE VIGENCIA',
            'periodo_credencial' => 'VIGENCIA 2025-2027',
            'numero_credencial' => 'CRED-' . uniqid(),
            'estatus_credencial' => 'ALTA',
            'es_referencia_loc' => true,
        ], $attributes));
    }

    public function test_dry_run_simulates_without_writing_to_database(): void
    {
        $corta = $this->createArma('CORTA-SIM-01', 'CORTA', 'PISTOLA');
        $larga = $this->createArma('LARGA-SIM-01', 'LARGA', 'FUSIL');

        $vigencia = $this->createVigencia([
            'matricula_corta' => 'CORTA-SIM-01',
            'matricula_larga' => 'LARGA-SIM-01',
            'arma_corta_id' => null,
            'arma_larga_id' => null,
            'arma_id' => null,
        ]);

        $results = $this->reconciler->run(chunkSize: 10, dryRun: true);

        $this->assertSame('simulacion', $results['modo']);
        $this->assertSame(1, $results['arma_corta']['enlace_nuevo']);
        $this->assertSame(1, $results['arma_larga']['enlace_nuevo']);
        $this->assertSame(2, $results['relaciones_nuevas_totales']);

        // Verificar que en base de datos NO se escribió nada
        $fresh = $vigencia->fresh();
        $this->assertNull($fresh->arma_corta_id);
        $this->assertNull($fresh->arma_larga_id);
        $this->assertNull($fresh->arma_id);
    }

    public function test_exact_match_for_both_corta_and_larga_in_same_vigencia(): void
    {
        $corta = $this->createArma('MAT-CORTA-100', 'CORTA', 'PISTOLA');
        $larga = $this->createArma('MAT-LARGA-200', 'LARGA', 'CARABINA');

        $vigencia = $this->createVigencia([
            'matricula_corta' => 'MAT-CORTA-100',
            'matricula_larga' => 'MAT-LARGA-200',
            'arma_corta_id' => null,
            'arma_larga_id' => null,
            'arma_id' => null,
        ]);

        $results = $this->reconciler->run(chunkSize: 10, dryRun: false);

        $this->assertSame(1, $results['arma_corta']['enlace_nuevo']);
        $this->assertSame(1, $results['arma_larga']['enlace_nuevo']);

        $fresh = $vigencia->fresh();
        $this->assertSame($corta->id, $fresh->arma_corta_id);
        $this->assertSame($larga->id, $fresh->arma_larga_id);
        $this->assertNull($fresh->arma_id, 'arma_id heredada no debe modificarse');
        $this->assertSame('MAT-CORTA-100', $fresh->matricula_corta);
        $this->assertSame('MAT-LARGA-200', $fresh->matricula_larga);
    }

    public function test_single_weapon_handling_for_only_corta_or_only_larga(): void
    {
        $corta = $this->createArma('SOLO-CORTA-01', 'CORTA', 'PISTOLA');
        $larga = $this->createArma('SOLO-LARGA-02', 'LARGA', 'ESCOPETA');

        $vigenciaCorta = $this->createVigencia([
            'matricula_corta' => 'SOLO-CORTA-01',
            'matricula_larga' => null,
        ]);

        $vigenciaLarga = $this->createVigencia([
            'matricula_corta' => null,
            'matricula_larga' => 'SOLO-LARGA-02',
        ]);

        $results = $this->reconciler->run(chunkSize: 10, dryRun: false);

        $this->assertSame(1, $results['arma_corta']['enlace_nuevo']);
        $this->assertSame(1, $results['arma_corta']['vacia_o_null']);
        $this->assertSame(1, $results['arma_larga']['enlace_nuevo']);
        $this->assertSame(1, $results['arma_larga']['vacia_o_null']);

        $freshCorta = $vigenciaCorta->fresh();
        $this->assertSame($corta->id, $freshCorta->arma_corta_id);
        $this->assertNull($freshCorta->arma_larga_id);

        $freshLarga = $vigenciaLarga->fresh();
        $this->assertNull($freshLarga->arma_corta_id);
        $this->assertSame($larga->id, $freshLarga->arma_larga_id);
    }

    public function test_unmatched_and_empty_serials_are_preserved_without_error(): void
    {
        $vigenciaSinMatch = $this->createVigencia([
            'matricula_corta' => 'MAT-FANTASMA-999',
            'matricula_larga' => null,
        ]);

        $vigenciaVacia = $this->createVigencia([
            'matricula_corta' => null,
            'matricula_larga' => null,
        ]);

        $results = $this->reconciler->run(chunkSize: 10, dryRun: false);

        $this->assertSame(0, $results['arma_corta']['enlace_nuevo']);
        $this->assertSame(1, $results['arma_corta']['sin_coincidencia_conservada']);
        $this->assertSame(1, $results['arma_corta']['vacia_o_null']);

        $freshSinMatch = $vigenciaSinMatch->fresh();
        $this->assertNull($freshSinMatch->arma_corta_id);
        $this->assertSame('MAT-FANTASMA-999', $freshSinMatch->matricula_corta);

        $freshVacia = $vigenciaVacia->fresh();
        $this->assertNull($freshVacia->arma_corta_id);
        $this->assertNull($freshVacia->arma_larga_id);
    }

    public function test_existing_correct_relationship_is_skipped_without_redundant_writes(): void
    {
        $corta = $this->createArma('EXISTENTE-01');

        $vigencia = $this->createVigencia([
            'matricula_corta' => 'EXISTENTE-01',
            'arma_corta_id' => $corta->id,
            'updated_at' => now()->subDay(),
        ]);

        $previousUpdatedAt = $vigencia->updated_at;

        $results = $this->reconciler->run(chunkSize: 10, dryRun: false);

        $this->assertSame(0, $results['arma_corta']['enlace_nuevo']);
        $this->assertSame(1, $results['arma_corta']['ya_enlazada_correcta']);
        $this->assertSame(0, $results['vigencias_con_actualizacion']);

        $fresh = $vigencia->fresh();
        $this->assertSame($corta->id, $fresh->arma_corta_id);
        $this->assertEquals($previousUpdatedAt->toDateTimeString(), $fresh->updated_at->toDateTimeString());
    }

    public function test_contradictory_existing_relationship_is_flagged_as_conflict_and_not_modified(): void
    {
        $armaOriginal = $this->createArma('MAT-ORIGINAL');
        $armaNueva = $this->createArma('MAT-NUEVA');

        // La vigencia dice MAT-NUEVA, pero tiene arma_corta_id apuntando a MAT-ORIGINAL
        $vigencia = $this->createVigencia([
            'matricula_corta' => 'MAT-NUEVA',
            'arma_corta_id' => $armaOriginal->id,
        ]);

        $results = $this->reconciler->run(chunkSize: 10, dryRun: false);

        $this->assertSame(1, $results['arma_corta']['conflicto_no_modificado']);
        $this->assertCount(1, $results['filas_conflicto']);
        $this->assertSame($vigencia->id, $results['filas_conflicto'][0]['vigencia_id']);

        // Se verifica que la base de datos NO fue sobreescrita
        $fresh = $vigencia->fresh();
        $this->assertSame($armaOriginal->id, $fresh->arma_corta_id, 'No debe sobrescribir relaciones contradictorias');
    }

    public function test_idempotency_on_repeated_runs(): void
    {
        $corta = $this->createArma('IDEMPOTENTE-01');
        $vigencia = $this->createVigencia([
            'matricula_corta' => 'IDEMPOTENTE-01',
            'arma_corta_id' => null,
        ]);

        // Primera corrida: enlaza
        $run1 = $this->reconciler->run(chunkSize: 10, dryRun: false);
        $this->assertSame(1, $run1['arma_corta']['enlace_nuevo']);

        // Segunda corrida: ya está enlazada, no debe actualizar
        $run2 = $this->reconciler->run(chunkSize: 10, dryRun: false);
        $this->assertSame(0, $run2['arma_corta']['enlace_nuevo']);
        $this->assertSame(1, $run2['arma_corta']['ya_enlazada_correcta']);
        $this->assertSame(0, $run2['vigencias_con_actualizacion']);

        $fresh = $vigencia->fresh();
        $this->assertSame($corta->id, $fresh->arma_corta_id);
    }

    public function test_multiple_vigencias_legitimately_share_same_weapon(): void
    {
        $armaCompartida = $this->createArma('COMPARTIDA-88');

        $v1 = $this->createVigencia(['numero_credencial' => 'CR-1', 'matricula_corta' => 'COMPARTIDA-88']);
        $v2 = $this->createVigencia(['numero_credencial' => 'CR-2', 'matricula_corta' => 'COMPARTIDA-88']);
        $v3 = $this->createVigencia(['numero_credencial' => 'CR-3', 'matricula_corta' => 'COMPARTIDA-88']);

        $results = $this->reconciler->run(chunkSize: 10, dryRun: false);

        $this->assertSame(3, $results['arma_corta']['enlace_nuevo']);

        $this->assertSame($armaCompartida->id, $v1->fresh()->arma_corta_id);
        $this->assertSame($armaCompartida->id, $v2->fresh()->arma_corta_id);
        $this->assertSame($armaCompartida->id, $v3->fresh()->arma_corta_id);
    }

    public function test_artisan_command_in_dry_run_mode(): void
    {
        $this->createArma('ARTISAN-CORTA');
        $this->createVigencia(['matricula_corta' => 'ARTISAN-CORTA']);

        $this->artisan('loc:conciliar-armas-vigencias', ['--dry-run' => true])
            ->expectsOutputToContain('MODO DE OPERACIÓN: [SIMULACIÓN / DRY-RUN]')
            ->expectsOutputToContain('Simulación completada satisfactoriamente')
            ->assertExitCode(0);

        // Sin modificaciones
        $this->assertDatabaseHas('loc_vigencias_credenciales', [
            'matricula_corta' => 'ARTISAN-CORTA',
            'arma_corta_id' => null,
        ]);
    }

    public function test_artisan_command_in_execute_mode_with_force(): void
    {
        $arma = $this->createArma('ARTISAN-EXEC');
        $this->createVigencia(['matricula_corta' => 'ARTISAN-EXEC']);

        $this->artisan('loc:conciliar-armas-vigencias', ['--execute' => true, '--force' => true])
            ->expectsOutputToContain('MODO DE OPERACIÓN: [PERSISTENCIA REAL / EXECUTE]')
            ->expectsOutputToContain('Conciliación de armamento completada exitosamente')
            ->assertExitCode(0);

        $this->assertDatabaseHas('loc_vigencias_credenciales', [
            'matricula_corta' => 'ARTISAN-EXEC',
            'arma_corta_id' => $arma->id,
            'arma_id' => null,
        ]);
    }

    public function test_artisan_command_cancels_when_confirmation_declined(): void
    {
        $this->createArma('ARTISAN-DECLINE');
        $this->createVigencia(['matricula_corta' => 'ARTISAN-DECLINE']);

        $this->artisan('loc:conciliar-armas-vigencias', ['--execute' => true])
            ->expectsConfirmation('¿Autoriza la ejecución real para vincular 1 relaciones de armamento?', 'no')
            ->expectsOutputToContain('Operación cancelada por el usuario')
            ->assertExitCode(0);

        $this->assertDatabaseHas('loc_vigencias_credenciales', [
            'matricula_corta' => 'ARTISAN-DECLINE',
            'arma_corta_id' => null,
        ]);
    }

    public function test_weapon_serial_normalization_handles_whitespace_and_edge_cases(): void
    {
        $armaClean = $this->createArma('MAT-CLEAN-01', 'CORTA');
        $armaLead = $this->createArma('MAT-LEAD-02', 'CORTA');
        $armaTrail = $this->createArma('MAT-TRAIL-03', 'LARGA', 'FUSIL');
        $armaBoth = $this->createArma('MAT-BOTH-04', 'CORTA');
        $armaDualCorta = $this->createArma('CORTA-DUAL-05', 'CORTA');
        $armaDualLarga = $this->createArma('LARGA-DUAL-05', 'LARGA', 'FUSIL');
        $armaConflictoExistente = $this->createArma('MAT-EXISTING-06', 'CORTA');
        $armaConflictoCandidato = $this->createArma('MAT-CONFLICT-06', 'CORTA');

        // 1. Sin espacios
        $vClean = $this->createVigencia(['matricula_corta' => 'MAT-CLEAN-01']);
        // 2. Con espacios al inicio
        $vLead = $this->createVigencia(['matricula_corta' => '   MAT-LEAD-02']);
        // 3. Con espacios al final
        $vTrail = $this->createVigencia(['matricula_larga' => 'MAT-TRAIL-03   ', 'matricula_corta' => null]);
        // 4. Con espacios a ambos lados
        $vBoth = $this->createVigencia(['matricula_corta' => '   MAT-BOTH-04   ']);
        // 5. Matrícula vacía / solo espacios
        $vEmpty = $this->createVigencia(['matricula_corta' => '   ', 'matricula_larga' => '']);
        // 6. Coincidencia para corta y larga simultáneas con espacios
        $vDual = $this->createVigencia([
            'matricula_corta' => "  CORTA-DUAL-05 \t",
            'matricula_larga' => " \n LARGA-DUAL-05  ",
        ]);
        // 7. Matrícula inexistente con espacios
        $vMissing = $this->createVigencia(['matricula_corta' => '   FANTASMA-999   ']);
        // 8. Conflicto con vínculo existente y espacios: no debe sobrescribir
        $vConflict = $this->createVigencia([
            'matricula_corta' => '   MAT-CONFLICT-06   ',
            'arma_corta_id' => $armaConflictoExistente->id,
        ]);

        $results = $this->reconciler->run(chunkSize: 50, dryRun: false);

        // Verificaciones métricas
        $this->assertSame(4, $results['arma_corta']['enlace_nuevo']); // Clean, Lead, Both, DualCorta
        $this->assertSame(2, $results['arma_larga']['enlace_nuevo']); // Trail, DualLarga
        $this->assertSame(1, $results['arma_corta']['sin_coincidencia_conservada']); // FANTASMA-999
        $this->assertSame(1, $results['arma_corta']['conflicto_no_modificado']); // Conflict
        $this->assertSame(2, $results['arma_corta']['vacia_o_null']); // Trail, Empty
        $this->assertSame(6, $results['arma_larga']['vacia_o_null']); // Clean, Lead, Both, Empty, Missing, Conflict

        // Verificaciones en base de datos
        $this->assertSame($armaClean->id, $vClean->fresh()->arma_corta_id);
        $this->assertSame('MAT-CLEAN-01', $vClean->fresh()->matricula_corta);

        $this->assertSame($armaLead->id, $vLead->fresh()->arma_corta_id);
        $this->assertSame('MAT-LEAD-02', $vLead->fresh()->matricula_corta);

        $this->assertSame($armaTrail->id, $vTrail->fresh()->arma_larga_id);
        $this->assertSame('MAT-TRAIL-03', $vTrail->fresh()->matricula_larga);

        $this->assertSame($armaBoth->id, $vBoth->fresh()->arma_corta_id);
        $this->assertSame('MAT-BOTH-04', $vBoth->fresh()->matricula_corta);

        $this->assertNull($vEmpty->fresh()->arma_corta_id);
        $this->assertNull($vEmpty->fresh()->arma_larga_id);

        $this->assertSame($armaDualCorta->id, $vDual->fresh()->arma_corta_id);
        $this->assertSame($armaDualLarga->id, $vDual->fresh()->arma_larga_id);
        $this->assertSame('CORTA-DUAL-05', $vDual->fresh()->matricula_corta);
        $this->assertSame('LARGA-DUAL-05', $vDual->fresh()->matricula_larga);

        $this->assertNull($vMissing->fresh()->arma_corta_id);
        $this->assertSame('FANTASMA-999', $vMissing->fresh()->matricula_corta);

        // Conflicto no sobrescrito
        $this->assertSame($armaConflictoExistente->id, $vConflict->fresh()->arma_corta_id);
    }
}

