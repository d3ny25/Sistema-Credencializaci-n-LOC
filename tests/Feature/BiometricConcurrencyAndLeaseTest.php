<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoIndexRunEstado;
use App\Models\BiometricoIndexLease;
use App\Models\BiometricoIndexRun;
use App\Services\BiometricIndexingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class BiometricConcurrencyAndLeaseTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bio_lease_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->eliminarDirectorioRecursivo($this->tempDir);
        parent::tearDown();
    }

    private function eliminarDirectorioRecursivo(string $dir): void
    {
        if (! file_exists($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path) && ! is_link($path)) {
                $this->eliminarDirectorioRecursivo($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function test_dos_workers_intentan_adquirir_simultaneamente_mismo_ambito_bloquea_segundo(): void
    {
        // Worker 1 adquiere lease simulado activo
        $run1 = BiometricoIndexRun::create([
            'ambito' => 'ESTATALES',
            'estado' => BiometricoIndexRunEstado::EN_EJECUCION,
            'iniciado_at' => now(),
        ]);

        BiometricoIndexLease::create([
            'ambito' => 'ESTATALES',
            'owner_token' => 'worker-token-1111',
            'biometrico_index_run_id' => $run1->id,
            'fencing_token' => 1,
            'lease_expires_at' => now()->addMinutes(10),
            'heartbeat_at' => now(),
            'worker_pid' => 1234,
            'worker_host' => 'node-01',
        ]);

        // Worker 2 intenta indexar el mismo ámbito ESTATALES
        $serviceWorker2 = new BiometricIndexingService(ownerToken: 'worker-token-2222');

        try {
            $serviceWorker2->indexar(
                ambito: BiometricoAmbito::ESTATALES,
                rootPath: $this->tempDir
            );
            $this->fail('Se esperaba RuntimeException por colisión de lease.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Ya existe un lease de indexación activo para el ámbito ESTATALES', $e->getMessage());
        }

        // AUD-243-001 / NUEVO-243-003: Comprobar que NO se creó ningún run espurio para el worker 2
        $this->assertSame(1, BiometricoIndexRun::where('ambito', 'ESTATALES')->count());
    }

    public function test_dos_workers_adquieren_ambitos_diferentes_sin_conflicto(): void
    {
        mkdir($this->tempDir . '/estatales', 0777, true);
        mkdir($this->tempDir . '/prevencion', 0777, true);

        $service1 = new BiometricIndexingService(ownerToken: 'worker-estatales');
        $service2 = new BiometricIndexingService(ownerToken: 'worker-prevencion');

        $run1 = $service1->indexar(BiometricoAmbito::ESTATALES, $this->tempDir . '/estatales');
        $run2 = $service2->indexar(BiometricoAmbito::PREVENCION, $this->tempDir . '/prevencion');

        $this->assertSame(BiometricoIndexRunEstado::COMPLETADA, $run1->estado);
        $this->assertSame(BiometricoIndexRunEstado::COMPLETADA, $run2->estado);
        $this->assertSame('ESTATALES', $run1->ambito);
        $this->assertSame('PREVENCION', $run2->ambito);
    }

    public function test_lease_expirado_es_recuperado_atomicamente_incrementando_fencing_token(): void
    {
        $runAntigua = BiometricoIndexRun::create([
            'ambito' => 'ESTATALES',
            'estado' => BiometricoIndexRunEstado::EN_EJECUCION,
            'iniciado_at' => now()->subHours(3),
        ]);

        // Lease expirado hace 30 minutos
        $lease = BiometricoIndexLease::create([
            'ambito' => 'ESTATALES',
            'owner_token' => 'worker-abandonado',
            'biometrico_index_run_id' => $runAntigua->id,
            'fencing_token' => 1,
            'lease_expires_at' => now()->subMinutes(30),
            'heartbeat_at' => now()->subMinutes(30),
            'worker_pid' => 9999,
            'worker_host' => 'node-muerto',
        ]);

        $nuevoWorker = new BiometricIndexingService(ownerToken: 'worker-recuperador');
        $runNueva = $nuevoWorker->indexar(BiometricoAmbito::ESTATALES, $this->tempDir);

        $this->assertSame(BiometricoIndexRunEstado::COMPLETADA, $runNueva->estado);

        // La corrida antigua debe haberse marcado como interrumpida
        $runAntigua->refresh();
        $this->assertSame(BiometricoIndexRunEstado::INTERRUMPIDA, $runAntigua->estado);
        $this->assertStringContainsString('Lease expirado', (string) $runAntigua->resumen_interrupcion);

        // El fencing token del nuevo worker debe haber sido 2
        $this->assertSame(2, $nuevoWorker->getFencingToken());
    }

    public function test_worker_antiguo_aborta_de_inmediato_si_pierde_fencing_token(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', 'bytes_foto');

        $worker1 = new BiometricIndexingService(
            ownerToken: 'worker-desalojado',
            leaseTtlSeconds: 1 // 1 segundo para forzar expiración rápida
        );

        // Simular que el lease es revocado / alterado en base de datos antes de renovar
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('perdió el lease de indexación');

        // Mock o interrupción simulada: alteramos el fencing token en la base de datos
        // cuando el worker intente renovar
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'biometrico_index_leases') && str_contains($query->sql, 'insert')) {
                // Modificar el fencing token en la BD justo después de la adquisición
                DB::table('biometrico_index_leases')->update(['fencing_token' => 999]);
            }
        });

        $worker1->indexar(BiometricoAmbito::ESTATALES, $this->tempDir);
    }

    public function test_libera_lease_al_finalizar_corrida_exitosamente(): void
    {
        $service = new BiometricIndexingService();
        $service->indexar(BiometricoAmbito::ESTATALES, $this->tempDir);

        $this->assertSame(0, BiometricoIndexLease::where('ambito', 'ESTATALES')->count());
    }
}

