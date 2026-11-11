<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoCategoria;
use App\Enums\BiometricoEstadoArchivo;
use App\Enums\BiometricoIncidenciaTipo;
use App\Enums\BiometricoIndexRunEstado;
use App\Models\BiometricoArchivo;
use App\Models\BiometricoArchivoVersion;
use App\Models\BiometricoExpediente;
use App\Models\BiometricoIncidencia;
use App\Models\BiometricoIndexLease;
use App\Models\BiometricoIndexRun;
use App\Models\BiometricoSlotCandidato;
use App\Models\LocCatalogoOrganizacional;
use App\Models\LocCatalogoVersion;
use App\Models\LocImportBatch;
use App\Models\LocPersona;
use App\Models\User;
use App\Services\BiometricIndexingService;
use App\Services\BiometricScannerService;
use App\Support\BiometricScanEntry;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class BiometricRemediationAudit243Test extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;
    private BiometricIndexingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BiometricIndexingService();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bio_remed_test_' . uniqid();
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

    public function test_aud_243_001_adquisicion_atomica_cero_runs_huerfanos_en_conflicto(): void
    {
        // Simular que el lease ya está adquirido por otro worker activo
        $runExistente = BiometricoIndexRun::create([
            'ambito' => 'ESTATALES',
            'estado' => BiometricoIndexRunEstado::EN_EJECUCION,
            'iniciado_at' => now(),
        ]);

        BiometricoIndexLease::create([
            'ambito' => 'ESTATALES',
            'owner_token' => 'worker-previo',
            'biometrico_index_run_id' => $runExistente->id,
            'fencing_token' => 1,
            'lease_expires_at' => now()->addMinutes(10),
            'heartbeat_at' => now(),
            'worker_pid' => 1234,
            'worker_host' => 'node-1',
        ]);

        $this->assertSame(1, BiometricoIndexRun::count());

        $workerConflicto = new BiometricIndexingService(ownerToken: 'worker-colision');

        try {
            $workerConflicto->indexar(BiometricoAmbito::ESTATALES, $this->tempDir);
            $this->fail('Se esperaba RuntimeException por conflicto de lease.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Ya existe un lease de indexación activo', $e->getMessage());
        }

        // Cero runs huérfanos o espurios creados
        $this->assertSame(1, BiometricoIndexRun::count());
    }

    public function test_aud_243_002_directorio_inaccesible_desactiva_deteccion_de_desaparecidos(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        $file1 = $fotosDir . '/AAAA690916H15136329.JPG';
        $file2 = $fotosDir . '/BBBB700101H12345678.JPG';
        file_put_contents($file1, 'foto1');
        file_put_contents($file2, 'foto2');

        // Registro inicial de ambos archivos
        $this->service->indexar(BiometricoAmbito::ESTATALES, $this->tempDir);
        $this->assertSame(2, BiometricoArchivo::count());

        // Simular un scanner que sufre un error de acceso / directorio inaccesible durante el escaneo
        $mockScanner = new class extends BiometricScannerService {
            public function scan(string $rootPath, ?string $startAfterRelativePath = null): Generator
            {
                $this->huboErroresDeRecorrido = true;
                yield new BiometricScanEntry(
                    rutaRelativa: 'FOTOS/INACCESIBLE',
                    rutaAbsoluta: $rootPath . '/FOTOS/INACCESIBLE',
                    nombreArchivo: 'INACCESIBLE',
                    extension: '',
                    tamanoBytes: 0,
                    mtime: null,
                    esLegible: false,
                    esEnlace: false,
                    esDirectorio: true,
                    esArchivoRegular: false,
                    esSistema: false,
                    esOculto: false,
                    esValido: false,
                    tipoEntrada: 'directorio_inaccesible',
                    motivoRechazo: 'Permisos insuficientes simulados',
                    codigoError: 'DIR_INACCESIBLE'
                );
            }
        };

        $serviceConFallo = new BiometricIndexingService(scanner: $mockScanner);
        $run = $serviceConFallo->indexar(BiometricoAmbito::ESTATALES, $this->tempDir);

        // Debido al fallo en el recorrido, la detección de desaparecidos DEBE abortarse
        $this->assertSame(0, $run->total_archivos_desaparecidos);
        $desaparecidosCount = BiometricoArchivo::where('estado_archivo', BiometricoEstadoArchivo::DESAPARECIDO)->count();
        $this->assertSame(0, $desaparecidosCount);
    }

    public function test_aud_243_002_interrupcion_explicita_evita_falsos_desaparecidos(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', 'foto1');
        file_put_contents($fotosDir . '/BBBB700101H12345678.JPG', 'foto2');

        $this->service->indexar(BiometricoAmbito::ESTATALES, $this->tempDir);
        $this->assertSame(2, BiometricoArchivo::count());

        // Nueva corrida con abortar() en el callback de progreso
        $serviceInterrumpible = new BiometricIndexingService();
        $run = $serviceInterrumpible->indexar(
            ambito: BiometricoAmbito::ESTATALES,
            rootPath: $this->tempDir,
            chunkSize: 1,
            progressCallback: function () use ($serviceInterrumpible) {
                $serviceInterrumpible->abortar();
            }
        );

        $this->assertSame(BiometricoIndexRunEstado::INTERRUMPIDA, $run->estado);
        $this->assertSame(0, $run->total_archivos_desaparecidos);
        $desaparecidosCount = BiometricoArchivo::where('estado_archivo', BiometricoEstadoArchivo::DESAPARECIDO)->count();
        $this->assertSame(0, $desaparecidosCount);
    }

    public function test_aud_243_003_reanudacion_con_casing_mixto_no_omite_archivos(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);

        // Nombres mixtos donde en ASCII 'BBBB' < 'aaaa', pero en strnatcasecmp 'aaaa' < 'BBBB' < 'cccc'
        file_put_contents($fotosDir . '/aaaa690916H15136329.JPG', '1');
        file_put_contents($fotosDir . '/BBBB700101H12345678.JPG', '2');
        file_put_contents($fotosDir . '/cccc800202M87654321.JPG', '3');

        $canonicalRoot = $this->service->getScanner()->validarRutaRaiz($this->tempDir);
        $fingerprint = BiometricIndexingService::calcularFingerprintConfiguracion(BiometricoAmbito::ESTATALES, $canonicalRoot);

        // Corrida previa interrumpida en el primer archivo (aaaa)
        BiometricoIndexRun::create([
            'ambito' => 'ESTATALES',
            'estado' => BiometricoIndexRunEstado::INTERRUMPIDA,
            'iniciado_at' => now()->subMinutes(10),
            'ultima_ruta_procesada' => 'FOTOS/aaaa690916H15136329.JPG',
            'ultimo_checkpoint_id' => 1,
            'metadata' => [
                'root_path' => $canonicalRoot,
                'config_fingerprint' => $fingerprint,
            ],
        ]);

        $runReanudada = $this->service->indexar(
            ambito: BiometricoAmbito::ESTATALES,
            rootPath: $this->tempDir,
            resume: true
        );

        $this->assertSame(BiometricoIndexRunEstado::COMPLETADA, $runReanudada->estado);
        $this->assertSame(2, $runReanudada->total_archivos_procesados);
        $this->assertSame('FOTOS/cccc800202M87654321.JPG', $runReanudada->ultima_ruta_procesada);
    }

    public function test_aud_243_005_reanudacion_con_raiz_diferente_es_rechazada(): void
    {
        $otraRaiz = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'otra_raiz_' . uniqid();
        mkdir($otraRaiz, 0777, true);

        try {
            BiometricoIndexRun::create([
                'ambito' => 'ESTATALES',
                'estado' => BiometricoIndexRunEstado::INTERRUMPIDA,
                'iniciado_at' => now()->subMinutes(10),
                'ultima_ruta_procesada' => 'FOTOS/archivo.jpg',
                'ultimo_checkpoint_id' => 1,
                'metadata' => ['root_path' => 'C:/RaizOriginalInexistente'],
            ]);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('la ruta raíz actual');

            $this->service->indexar(
                ambito: BiometricoAmbito::ESTATALES,
                rootPath: $otraRaiz,
                resume: true
            );
        } finally {
            $this->eliminarDirectorioRecursivo($otraRaiz);
        }
    }

    public function test_aud_243_005_reanudacion_con_config_fingerprint_alterado_es_rechazada(): void
    {
        $canonicalRoot = $this->service->getScanner()->validarRutaRaiz($this->tempDir);

        BiometricoIndexRun::create([
            'ambito' => 'ESTATALES',
            'estado' => BiometricoIndexRunEstado::INTERRUMPIDA,
            'iniciado_at' => now()->subMinutes(10),
            'ultima_ruta_procesada' => 'FOTOS/archivo.jpg',
            'ultimo_checkpoint_id' => 1,
            'metadata' => [
                'root_path' => $canonicalRoot,
                'config_fingerprint' => 'fingerprint_falso_o_corrompido_12345',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('fingerprint de configuración');

        $this->service->indexar(
            ambito: BiometricoAmbito::ESTATALES,
            rootPath: $this->tempDir,
            resume: true
        );
    }

    public function test_aud_243_006_cadena_genealogica_sucesiva_de_modificaciones_sha256(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        $filePath = $fotosDir . '/AAAA690916H15136329.JPG';

        // 1. Contenido inicial A
        file_put_contents($filePath, 'version_A');
        $shaA = hash('sha256', 'version_A');

        $this->service->indexar(BiometricoAmbito::ESTATALES, $this->tempDir);
        $archivo = BiometricoArchivo::first();
        $this->assertSame($shaA, $archivo->sha256);
        $this->assertSame(1, $archivo->version_secuencia);

        // 2. Modificación a B
        file_put_contents($filePath, 'version_B');
        $shaB = hash('sha256', 'version_B');

        $run2 = $this->service->indexar(BiometricoAmbito::ESTATALES, $this->tempDir);
        $this->assertSame(1, $run2->total_archivos_modificados);

        $archivo->refresh();
        $this->assertSame($shaB, $archivo->sha256);
        $this->assertSame(2, $archivo->version_secuencia);

        $v1 = BiometricoArchivoVersion::where('version_secuencia', 2)->first();
        $this->assertNotNull($v1);
        $this->assertSame($shaA, $v1->sha256_anterior);
        $this->assertSame($shaB, $v1->sha256_nuevo);

        // 3. Modificación subsiguiente a C (verificar que no se rompe la cadena genealógica)
        file_put_contents($filePath, 'version_C');
        $shaC = hash('sha256', 'version_C');

        $run3 = $this->service->indexar(BiometricoAmbito::ESTATALES, $this->tempDir);
        $this->assertSame(1, $run3->total_archivos_modificados);

        $archivo->refresh();
        $this->assertSame($shaC, $archivo->sha256);
        $this->assertSame(3, $archivo->version_secuencia);

        $v2 = BiometricoArchivoVersion::where('version_secuencia', 3)->first();
        $this->assertNotNull($v2);
        // La versión 3 debe tener como hash anterior a B (no a A)
        $this->assertSame($shaB, $v2->sha256_anterior);
        $this->assertSame($shaC, $v2->sha256_nuevo);

        $this->assertSame(2, BiometricoArchivoVersion::where('biometrico_archivo_id', $archivo->id)->count());
    }

    public function test_aud_243_007_segregacion_institucional_prohibe_expediente_en_ambito_erroneo_y_cuarentena(): void
    {
        $admin = new User(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'pass', 'role' => 'admin']);
        $admin->save();

        $batch = LocImportBatch::create([
            'tipo_fuente' => 'catalogo_organizacional_loc',
            'nombre_archivo' => 'cat.xlsx',
            'estado' => 'completado',
            'usuario_id' => $admin->id,
            'fecha_confirmacion' => now(),
            'total_leidas' => 1,
            'total_insertadas' => 1,
        ]);

        LocCatalogoVersion::create([
            'import_batch_id' => $batch->id,
            'version_numero' => 1,
            'fecha_publicacion' => now(),
            'es_vigente' => true,
        ]);

        LocCatalogoOrganizacional::create([
            'import_batch_id' => $batch->id,
            'clave_origen' => 'SP01',
            'direccion_general' => 'DIR. GRAL. SEG. PUB. Y TTO.',
            'coordinacion' => 'SUBDIR. OPERATIVA',
            'activo' => true,
        ]);

        // Policía estatal oficial
        $persona = new LocPersona();
        $persona->cuip = 'AAAA690916H15136329';
        $persona->nombre = 'AGENTE ESTATAL';
        $persona->clave_seguridad = 'SP01';
        $persona->save();

        // Expediente oficial preexistente en ESTATALES
        $expOficial = BiometricoExpediente::create([
            'cuip' => 'AAAA690916H15136329',
            'ambito' => 'ESTATALES',
            'persona_id' => $persona->id,
            'tiene_anomalias' => false,
            'estado_revision' => 'sin_evaluar',
        ]);

        // Archivo colocado erróneamente en carpeta física de PREVENCION
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', 'foto_en_carpeta_erronea');

        // Escanear bajo PREVENCION
        $run = $this->service->indexar(BiometricoAmbito::PREVENCION, $this->tempDir);

        // 1. Incidencia registrada
        $incidencia = BiometricoIncidencia::where('codigo', 'DISCREPANCIA_AMBITO_CATALOGO')->first();
        $this->assertNotNull($incidencia);
        $this->assertSame(BiometricoIncidenciaTipo::CONFLICTO_IDENTIDAD, $incidencia->tipo);

        // 2. Archivo en cuarentena preventiva
        $archivo = BiometricoArchivo::first();
        $this->assertSame(BiometricoEstadoArchivo::CUARENTENA, $archivo->estado_archivo);
        $this->assertStringContainsString('DISCREPANCIA_AMBITO', (string) $archivo->observaciones);

        // 3. PROHIBIDO crear expediente en PREVENCION
        $this->assertSame(0, BiometricoExpediente::where('ambito', 'PREVENCION')->count());

        // 4. Expediente oficial en ESTATALES queda con anomalía y en aclaración
        $expOficial->refresh();
        $this->assertTrue($expOficial->tiene_anomalias);
        $this->assertSame('en_aclaracion', $expOficial->estado_revision->value ?? $expOficial->estado_revision);
        $this->assertStringContainsString('Archivo extraviado detectado en PREVENCION', (string) $expOficial->motivo_rechazo_biometrico);
    }

    public function test_aud_243_008_colision_de_slot_congela_slot_registra_candidatos_y_pone_en_aclaracion(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);

        // Foto 1
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', 'foto_1_contenido');
        $this->service->indexar(BiometricoAmbito::ESTATALES, $this->tempDir);

        $expediente = BiometricoExpediente::first();
        $this->assertNotNull($expediente->foto_archivo_id);
        $this->assertSame(1, BiometricoSlotCandidato::count());
        $candidato1 = BiometricoSlotCandidato::first();
        $this->assertTrue($candidato1->es_seleccion_oficial);
        $this->assertSame('seleccionado', $candidato1->estado_candidato);

        // Foto 2 para el mismo CUIP pero contenido binario diferente en subcarpeta válida
        $subDir = $fotosDir . '/SUBDIR';
        mkdir($subDir, 0777, true);
        file_put_contents($subDir . '/AAAA690916H15136329.JPG', 'foto_2_contenido_distinto');

        $this->service->indexar(BiometricoAmbito::ESTATALES, $this->tempDir);

        // El slot en el expediente DEBE CONGELARSE A NULL para evitar credencialización arbitraria
        $expediente->refresh();
        $this->assertNull($expediente->foto_archivo_id);
        $this->assertTrue($expediente->tiene_duplicados);
        $this->assertTrue($expediente->tiene_anomalias);
        $this->assertSame('en_aclaracion', $expediente->estado_revision->value ?? $expediente->estado_revision);

        // Ambos candidatos deben estar registrados en estado 'en_conflicto'
        $this->assertSame(2, BiometricoSlotCandidato::where('biometrico_expediente_id', $expediente->id)->count());
        $candidatosEnConflicto = BiometricoSlotCandidato::where('biometrico_expediente_id', $expediente->id)
            ->where('estado_candidato', 'en_conflicto')
            ->count();
        $this->assertSame(2, $candidatosEnConflicto);

        $incidencia = BiometricoIncidencia::where('codigo', 'CONFLICTO_SLOT_MULTIPLE')->first();
        $this->assertNotNull($incidencia);
    }
}
