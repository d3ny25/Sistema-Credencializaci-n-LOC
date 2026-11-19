<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoIndexRunEstado;
use App\Exceptions\BiometricoLeaseOcupadoException;
use App\Exceptions\BiometricoLeasePerdidoException;
use App\Models\BiometricoArchivo;
use App\Models\BiometricoIndexLease;
use App\Models\BiometricoIndexRun;
use App\Services\BiometricIndexingService;
use App\Support\StreamingHashCalculator;
use App\Support\StreamingHashResult;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * H-02: renovación con fencing, desplazamiento, --takeover, --cancel, estados terminales y --resume.
 * Determinista sobre SQLite: la concurrencia real entre conexiones se verifica aparte en MariaDB aislada.
 * Los CUIP son sintéticos.
 */
class BiometricLeaseH02Test extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bio_h02_' . uniqid();
        mkdir($this->tempDir . '/FOTOS', 0777, true);
        foreach ([1, 2, 3] as $i) {
            file_put_contents($this->tempDir . "/FOTOS/XXXX00010{$i}H0000000{$i}.JPG", "foto-sintetica-{$i}");
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/FOTOS/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tempDir . '/FOTOS');
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    /** Servicio cuyo hasher ejecuta $alHashear(n) dentro de la transacción del lote n (chunkSize 1). */
    private function servicio(Closure $alHashear, ?string $owner = null): BiometricIndexingService
    {
        $hasher = new class ($alHashear) extends StreamingHashCalculator
        {
            private int $n = 0;

            public function __construct(private Closure $alHashear)
            {
                parent::__construct();
            }

            public function calculate(string $filePath): StreamingHashResult
            {
                ($this->alHashear)(++$this->n);

                return parent::calculate($filePath);
            }
        };

        return new BiometricIndexingService(hasher: $hasher, ownerToken: $owner);
    }

    private function runEnCurso(): BiometricoIndexRun
    {
        return BiometricoIndexRun::query()->latest('id')->firstOrFail();
    }

    public function test_d_e_lote_mas_largo_que_el_ttl_renueva_sin_perder_la_propiedad(): void
    {
        $renovadoAlFuturo = null;
        $service = $this->servicio(function (int $n) use (&$renovadoAlFuturo): void {
            if ($n === 1) {
                $this->travel(10)->minutes(); // el lote 1 "tarda" más que el TTL de 300 s
            }
            if ($n === 2) {
                $renovadoAlFuturo = BiometricoIndexLease::firstOrFail()->lease_expires_at->isFuture();
            }
        });

        $run = $service->indexar(BiometricoAmbito::ESTATALES, $this->tempDir, chunkSize: 1);

        $this->assertSame(BiometricoIndexRunEstado::COMPLETADA, $run->estado);
        $this->assertTrue($renovadoAlFuturo, 'El lote 2 debió renovar el lease vencido porque nadie lo había tomado.');
        $this->assertSame(3, BiometricoArchivo::count());
        $this->assertSame(0, BiometricoIndexLease::count());
    }

    public function test_f_n_proceso_desplazado_aborta_sin_escribir_y_no_libera_el_lease_ajeno(): void
    {
        $service = $this->servicio(function (int $n): void {
            if ($n === 1) {
                // Otro proceso adquiere el ámbito (takeover): cambia owner y fencing.
                DB::table('biometrico_index_leases')->update([
                    'owner_token' => 'worker-B',
                    'fencing_token' => 2,
                    'lease_expires_at' => now()->addMinutes(5),
                ]);
            }
        });

        try {
            $service->indexar(BiometricoAmbito::ESTATALES, $this->tempDir, chunkSize: 1);
            $this->fail('El proceso desplazado debió abortar.');
        } catch (BiometricoLeasePerdidoException $e) {
            $this->assertStringContainsString('perdió el lease de indexación', $e->getMessage());
        }

        $run = $this->runEnCurso();
        $this->assertSame(1, BiometricoArchivo::count(), 'Solo el lote confirmado antes del desplazamiento.');
        $this->assertSame(1, $run->total_archivos_procesados, 'Los contadores no incluyen el lote revertido.');
        $this->assertSame(BiometricoIndexRunEstado::INTERRUMPIDA, $run->estado);
        $this->assertStringContainsString('perdió el lease', (string) $run->resumen_interrupcion);
        $this->assertSame('worker-B', BiometricoIndexLease::firstOrFail()->owner_token);
    }

    public function test_i_cancelacion_durante_la_ejecucion_termina_como_cancelada_en_punto_seguro(): void
    {
        $service = $this->servicio(function (int $n): void {
            if ($n === 1) {
                (new BiometricIndexingService())->cancelarRun($this->runEnCurso()->id, 'Cancelación de prueba');
            }
        });

        $run = $service->indexar(BiometricoAmbito::ESTATALES, $this->tempDir, chunkSize: 1);

        $this->assertSame(BiometricoIndexRunEstado::CANCELADA, $run->fresh()->estado);
        $this->assertSame('Cancelación de prueba', $run->fresh()->resumen_interrupcion);
        $this->assertSame(1, BiometricoArchivo::count());
        $this->assertNotNull($run->fresh()->ultima_ruta_procesada, 'Conserva el checkpoint del lote confirmado.');
        $this->assertSame(0, BiometricoIndexLease::count());
    }

    public function test_j_cancelacion_inmediata_y_perdida_de_lease_no_sobrescribe_cancelada(): void
    {
        $service = $this->servicio(function (int $n): void {
            if ($n === 1) {
                $this->travel(10)->minutes(); // propietario lento: lease vencido → --cancel es inmediato
                (new BiometricIndexingService())->cancelarRun($this->runEnCurso()->id, 'Cancelada sin propietario vigente');
            }
        });

        $this->expectException(BiometricoLeasePerdidoException::class);

        try {
            $service->indexar(BiometricoAmbito::ESTATALES, $this->tempDir, chunkSize: 1);
        } finally {
            $run = $this->runEnCurso();
            $this->assertSame(BiometricoIndexRunEstado::CANCELADA, $run->estado);
            $this->assertSame('Cancelada sin propietario vigente', $run->resumen_interrupcion);
            $this->assertSame(1, BiometricoArchivo::count());
        }
    }

    public function test_j_excepcion_posterior_a_un_estado_terminal_no_lo_sobrescribe_con_fallida(): void
    {
        $service = new BiometricIndexingService();

        try {
            $service->indexar(
                BiometricoAmbito::ESTATALES,
                $this->tempDir,
                chunkSize: 1,
                progressCallback: function (int $p, int $d, BiometricoIndexRun $run): void {
                    BiometricoIndexRun::whereKey($run->id)->update(['estado' => BiometricoIndexRunEstado::CANCELADA->value]);
                    throw new RuntimeException('fallo simulado');
                }
            );
            $this->fail('La excepción debió propagarse.');
        } catch (RuntimeException $e) {
            $this->assertSame('fallo simulado', $e->getMessage());
        }

        $run = $this->runEnCurso();
        $this->assertSame(BiometricoIndexRunEstado::CANCELADA, $run->estado);
        $this->assertNull($run->error_mensaje);
    }

    public function test_m_excepcion_durante_un_lote_revierte_el_lote_y_deja_estado_y_checkpoint_coherentes(): void
    {
        $primera = null;
        DB::listen(function ($query) use (&$primera): void {
            if (str_contains($query->sql, 'insert into "biometrico_archivos"')) {
                if ($primera !== null) {
                    throw new RuntimeException('fallo simulado de escritura');
                }
                $primera = $query->bindings;
            }
        });

        try {
            (new BiometricIndexingService())->indexar(BiometricoAmbito::ESTATALES, $this->tempDir, chunkSize: 1);
            $this->fail('La excepción debió propagarse.');
        } catch (RuntimeException $e) {
            $this->assertSame('fallo simulado de escritura', $e->getMessage());
        }

        $run = $this->runEnCurso();
        $this->assertSame(BiometricoIndexRunEstado::FALLIDA, $run->estado);
        $this->assertSame(1, BiometricoArchivo::count());
        $this->assertSame(1, $run->total_archivos_procesados);
        $this->assertSame(BiometricoArchivo::firstOrFail()->ruta_relativa, $run->ultima_ruta_procesada);
        $this->assertSame(0, BiometricoIndexLease::count());
    }

    public function test_adquisicion_simultanea_por_insercion_traduce_la_violacion_unica_sin_runs_huerfanos(): void
    {
        $insertado = false;
        DB::listen(function ($query) use (&$insertado): void {
            if (! $insertado && str_contains($query->sql, 'insert into "biometrico_index_runs"')) {
                $insertado = true;
                // Otro proceso inserta su lease del mismo ámbito en el mismo instante.
                DB::table('biometrico_index_leases')->insert([
                    'ambito' => 'ESTATALES', 'owner_token' => 'worker-B', 'biometrico_index_run_id' => (int) DB::getPdo()->lastInsertId(),
                    'fencing_token' => 1, 'lease_expires_at' => now()->addMinutes(5), 'heartbeat_at' => now(),
                ]);
            }
        });

        try {
            (new BiometricIndexingService())->indexar(BiometricoAmbito::ESTATALES, $this->tempDir);
            $this->fail('La adquisición simultánea debió rechazarse.');
        } catch (BiometricoLeaseOcupadoException $e) {
            $this->assertStringContainsString('en el mismo instante', $e->getMessage());
            $this->assertStringNotContainsString('SQLSTATE', $e->getMessage());
        }

        $this->assertSame(0, BiometricoIndexRun::count());
    }

    private function leaseAjeno(bool $vencido): BiometricoIndexRun
    {
        $run = BiometricoIndexRun::create([
            'ambito' => 'ESTATALES',
            'estado' => BiometricoIndexRunEstado::EN_EJECUCION,
            'iniciado_at' => now()->subHour(),
        ]);
        BiometricoIndexLease::create([
            'ambito' => 'ESTATALES',
            'owner_token' => 'worker-anterior',
            'biometrico_index_run_id' => $run->id,
            'fencing_token' => 1,
            'lease_expires_at' => $vencido ? now()->subMinutes(30) : now()->addMinutes(5),
            'heartbeat_at' => now()->subMinutes(35),
        ]);

        return $run;
    }

    public function test_g_lease_vencido_sin_takeover_se_rechaza_aun_con_force(): void
    {
        $anterior = $this->leaseAjeno(vencido: true);

        $this->artisan('loc:biometricos:indexar', ['--ambito' => 'ESTATALES', '--path' => $this->tempDir, '--force' => true])
            ->expectsOutputToContain('--takeover')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(1, BiometricoIndexRun::count());
        $this->assertSame(BiometricoIndexRunEstado::EN_EJECUCION, $anterior->fresh()->estado);
        $this->assertSame('worker-anterior', BiometricoIndexLease::firstOrFail()->owner_token);
    }

    public function test_h_takeover_recupera_lease_vencido_e_invalida_al_propietario_anterior(): void
    {
        $anterior = $this->leaseAjeno(vencido: true);

        $this->artisan('loc:biometricos:indexar', ['--ambito' => 'ESTATALES', '--path' => $this->tempDir, '--force' => true, '--takeover' => true])
            ->assertExitCode(Command::SUCCESS);

        $anterior->refresh();
        $nueva = $this->runEnCurso();
        $this->assertSame(BiometricoIndexRunEstado::INTERRUMPIDA, $anterior->estado);
        $this->assertStringContainsString('Lease expirado', (string) $anterior->resumen_interrupcion);
        $this->assertNotNull($anterior->finalizado_at);
        $this->assertSame(2, $nueva->metadata['fencing_token']);
        $this->assertSame($anterior->id, $nueva->metadata['takeover_de_run_id']);
        $this->assertSame(BiometricoIndexRunEstado::COMPLETADA, $nueva->estado);
    }

    public function test_h_takeover_nunca_desplaza_a_un_propietario_vigente(): void
    {
        $anterior = $this->leaseAjeno(vencido: false);

        $this->artisan('loc:biometricos:indexar', ['--ambito' => 'ESTATALES', '--path' => $this->tempDir, '--force' => true, '--takeover' => true])
            ->expectsOutputToContain('Ya existe un lease de indexación activo')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(BiometricoIndexRunEstado::EN_EJECUCION, $anterior->fresh()->estado);
        $this->assertSame(1, BiometricoIndexRun::count());
    }

    public function test_takeover_respeta_una_cancelacion_pedida_sobre_la_corrida_desplazada(): void
    {
        $anterior = $this->leaseAjeno(vencido: true);
        $anterior->update(['metadata' => ['cancelacion_solicitada_at' => now()->toIso8601String(), 'cancelacion_motivo' => 'Cancelada antes del takeover']]);

        (new BiometricIndexingService())->indexar(BiometricoAmbito::ESTATALES, $this->tempDir, takeover: true);

        $this->assertSame(BiometricoIndexRunEstado::CANCELADA, $anterior->fresh()->estado);
        $this->assertSame('Cancelada antes del takeover', $anterior->fresh()->resumen_interrupcion);
    }

    private function corridaInterrumpida(): BiometricoIndexRun
    {
        $service = new BiometricIndexingService();
        $raiz = $service->getScanner()->validarRutaRaiz($this->tempDir);

        return BiometricoIndexRun::create([
            'ambito' => 'ESTATALES',
            'estado' => BiometricoIndexRunEstado::INTERRUMPIDA,
            'iniciado_at' => now()->subMinutes(10),
            'ultima_ruta_procesada' => 'FOTOS/XXXX000101H00000001.JPG',
            'metadata' => [
                'root_path' => $raiz,
                'config_fingerprint' => BiometricIndexingService::calcularFingerprintConfiguracion(BiometricoAmbito::ESTATALES, $raiz),
            ],
        ]);
    }

    public function test_k_una_corrida_solo_puede_reanudarse_una_vez(): void
    {
        $origen = $this->corridaInterrumpida();

        $reanudacion = (new BiometricIndexingService())->indexar(
            BiometricoAmbito::ESTATALES, $this->tempDir, resume: true, targetResumeIdOrUuid: $origen->id
        );

        $this->assertSame(BiometricoIndexRunEstado::COMPLETADA, $reanudacion->estado);
        $this->assertSame(2, $reanudacion->total_archivos_procesados);
        $this->assertSame($reanudacion->uuid, $origen->fresh()->metadata['reanudada_por_uuid']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sin haber sido reanudada antes');
        (new BiometricIndexingService())->indexar(
            BiometricoAmbito::ESTATALES, $this->tempDir, resume: true, targetResumeIdOrUuid: $origen->id
        );
    }

    public function test_k_no_se_reanuda_una_corrida_que_aun_referencia_un_lease(): void
    {
        $origen = $this->corridaInterrumpida();
        BiometricoIndexLease::create([
            'ambito' => 'PREVENCION', 'owner_token' => 'worker-X', 'biometrico_index_run_id' => $origen->id,
            'fencing_token' => 1, 'lease_expires_at' => now()->addMinutes(5), 'heartbeat_at' => now(),
        ]);

        $this->expectException(InvalidArgumentException::class);
        (new BiometricIndexingService())->indexar(
            BiometricoAmbito::ESTATALES, $this->tempDir, resume: true, targetResumeIdOrUuid: $origen->id
        );
    }
}
