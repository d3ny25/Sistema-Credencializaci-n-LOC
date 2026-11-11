<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoCategoria;
use App\Enums\BiometricoEstadoArchivo;
use App\Enums\BiometricoEstadoRevision;
use App\Enums\BiometricoIncidenciaSeveridad;
use App\Enums\BiometricoIncidenciaTipo;
use App\Enums\BiometricoIndexRunEstado;
use App\Models\BiometricoArchivo;
use App\Models\BiometricoExpediente;
use App\Models\BiometricoIncidencia;
use App\Models\BiometricoIndexRun;
use App\Models\LocPersona;
use App\Models\User;
use App\Services\BiometricIndexingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BiometricIndexingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;
    private BiometricIndexingService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BiometricIndexingService();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bio_idx_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);

        $this->user = new User();
        $this->user->name = 'Auditor Biometria';
        $this->user->email = 'auditor_' . uniqid() . '@example.com';
        $this->user->password = bcrypt('secret123');
        $this->user->role = 'admin';
        $this->user->save();
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

    public function test_indexacion_archivo_nuevo_registra_archivo_y_crea_expediente(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        $fotoPath = $fotosDir . '/AAAA690916H15136329.JPG';
        file_put_contents($fotoPath, 'bytes_imagen_foto_facial_real');

        $run = $this->service->indexar(
            ambito: BiometricoAmbito::ESTATALES,
            rootPath: $this->tempDir,
            chunkSize: 10,
            iniciadoPor: $this->user
        );

        $this->assertSame(BiometricoIndexRunEstado::COMPLETADA, $run->estado);
        $this->assertSame(1, $run->total_archivos_detectados);
        $this->assertSame(1, $run->total_archivos_procesados);
        $this->assertSame(1, $run->total_archivos_nuevos);
        $this->assertSame(0, $run->total_incidencias);

        // Verificar persistencia en biometrico_archivos
        $archivo = BiometricoArchivo::query()->where('ruta_relativa', 'FOTOS/AAAA690916H15136329.JPG')->first();
        $this->assertNotNull($archivo);
        $this->assertSame(BiometricoAmbito::ESTATALES, $archivo->ambito);
        $this->assertSame(BiometricoCategoria::FOTO, $archivo->categoria);
        $this->assertSame('AAAA690916H15136329', $archivo->cuip_normalizado);
        $this->assertSame(BiometricoEstadoArchivo::VALIDO, $archivo->estado_archivo);
        $this->assertSame(hash('sha256', 'bytes_imagen_foto_facial_real'), $archivo->sha256);

        // Verificar vinculación en biometrico_expedientes
        $expediente = BiometricoExpediente::query()
            ->where('cuip', 'AAAA690916H15136329')
            ->where('ambito', 'ESTATALES')
            ->first();

        $this->assertNotNull($expediente);
        $this->assertSame($archivo->id, $expediente->foto_archivo_id);
        $this->assertNull($expediente->firma_archivo_id);
        $this->assertSame(1, $expediente->total_biometricos);
        $this->assertFalse($expediente->es_completo);
        $this->assertSame(BiometricoEstadoRevision::SIN_EVALUAR, $expediente->estado_revision);
    }

    public function test_indexacion_archivo_sin_cambios_no_duplica_escrituras(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', 'bytes_fijos');

        // Primera ejecución: registra archivo nuevo
        $run1 = $this->service->indexar(
            ambito: BiometricoAmbito::ESTATALES,
            rootPath: $this->tempDir
        );
        $this->assertSame(1, $run1->total_archivos_nuevos);
        $this->assertSame(0, $run1->total_archivos_sin_cambios);

        // Segunda ejecución sobre el mismo archivo intacto
        $run2 = $this->service->indexar(
            ambito: BiometricoAmbito::ESTATALES,
            rootPath: $this->tempDir
        );
        $this->assertSame(0, $run2->total_archivos_nuevos);
        $this->assertSame(1, $run2->total_archivos_sin_cambios);
        $this->assertSame(1, BiometricoArchivo::count());
    }

    public function test_indexacion_archivo_modificado_conserva_historial_y_registra_incidencia_critica(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        $file = $fotosDir . '/AAAA690916H15136329.JPG';
        file_put_contents($file, 'bytes_iniciales');

        // Primera indexación
        $this->service->indexar(BiometricoAmbito::ESTATALES, $this->tempDir);
        $archivoInicial = BiometricoArchivo::first();
        $shaInicial = $archivoInicial->sha256;

        // Modificar contenido en disco
        file_put_contents($file, 'bytes_alterados_maliciosamente_o_modificados');
        $shaModificado = hash('sha256', 'bytes_alterados_maliciosamente_o_modificados');

        // Segunda indexación
        $run2 = $this->service->indexar(BiometricoAmbito::ESTATALES, $this->tempDir);

        $this->assertSame(1, $run2->total_archivos_modificados);
        $this->assertSame(0, $run2->total_archivos_nuevos);
        $this->assertSame(BiometricoIndexRunEstado::COMPLETADA_CON_INCIDENCIAS, $run2->estado);

        // Se conserva un único registro (sin sobreescritura destructiva ni duplicados de ruta)
        $this->assertSame(1, BiometricoArchivo::count());
        $archivoActualizado = BiometricoArchivo::first();
        $this->assertSame(BiometricoEstadoArchivo::SOSPECHOSO, $archivoActualizado->estado_archivo);
        $this->assertStringContainsString('[MODIFICADO]', (string) $archivoActualizado->observaciones);

        // Se registró incidencia crítica con los detalles del cambio
        $incidencia = BiometricoIncidencia::where('codigo', 'HASH_MODIFICADO')->first();
        $this->assertNotNull($incidencia);
        $this->assertSame(BiometricoIncidenciaTipo::ERROR_PROCESAMIENTO, $incidencia->tipo);
        $this->assertSame(BiometricoIncidenciaSeveridad::CRITICA, $incidencia->severidad);
        $this->assertSame($shaInicial, $incidencia->detalles['sha256_anterior']);
        $this->assertSame($shaModificado, $incidencia->detalles['sha256_nuevo']);
    }

    public function test_indexacion_duplicado_por_contenido_registra_estado_duplicado_sha_e_incidencia(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);

        // Dos archivos distintos con el mismo hash binario idéntico
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', 'mismo_contenido_binario');
        file_put_contents($fotosDir . '/BBBB700101H12345678.JPG', 'mismo_contenido_binario');

        $run = $this->service->indexar(BiometricoAmbito::ESTATALES, $this->tempDir);

        $this->assertSame(2, $run->total_archivos_procesados);
        $this->assertSame(2, $run->total_archivos_nuevos);
        $this->assertSame(1, $run->total_archivos_duplicados);
        $this->assertSame(1, $run->total_incidencias);

        $archivos = BiometricoArchivo::orderBy('id')->get();
        $this->assertSame(BiometricoEstadoArchivo::VALIDO, $archivos[0]->estado_archivo);
        $this->assertSame(BiometricoEstadoArchivo::DUPLICADO_SHA, $archivos[1]->estado_archivo);

        $incidencia = BiometricoIncidencia::where('codigo', 'DUPLICADO_CONTENIDO')->first();
        $this->assertNotNull($incidencia);
        $this->assertSame(BiometricoIncidenciaTipo::ARCHIVO_DUPLICADO, $incidencia->tipo);
    }

    public function test_indexacion_archivos_invalidos_y_de_sistema(): void
    {
        file_put_contents($this->tempDir . '/desktop.ini', '[.ShellClassInfo]');
        file_put_contents($this->tempDir . '/foto_sin_cuip.jpg', 'imagen_anonima');

        $run = $this->service->indexar(BiometricoAmbito::ESTATALES, $this->tempDir);

        $this->assertGreaterThanOrEqual(1, $run->total_archivos_invalidos);
        $this->assertSame(0, BiometricoExpediente::count());

        $incidencias = BiometricoIncidencia::all();
        $codigos = $incidencias->pluck('codigo')->all();
        $this->assertContains('ARCHIVO_SISTEMA', $codigos);
    }

    public function test_indexacion_dry_run_no_persiste_en_base_de_datos(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', 'datos_foto');

        $run = $this->service->indexar(
            ambito: BiometricoAmbito::ESTATALES,
            rootPath: $this->tempDir,
            dryRun: true
        );

        $this->assertTrue($run->es_dry_run);
        $this->assertSame(1, $run->total_archivos_nuevos);
        $this->assertSame(0, BiometricoArchivo::count());
        $this->assertSame(0, BiometricoExpediente::count());
        $this->assertSame(0, BiometricoIncidencia::count());
    }

    public function test_control_de_concurrencia_bloquea_ejecuciones_simultaneas(): void
    {
        // Crear una corrida activa en ejecución con lease asignado
        $run = BiometricoIndexRun::create([
            'ambito' => 'ESTATALES',
            'estado' => BiometricoIndexRunEstado::EN_EJECUCION,
            'iniciado_at' => now(),
        ]);

        \App\Models\BiometricoIndexLease::create([
            'ambito' => 'ESTATALES',
            'owner_token' => 'otro-worker-activo',
            'biometrico_index_run_id' => $run->id,
            'fencing_token' => 1,
            'lease_expires_at' => now()->addMinutes(10),
            'heartbeat_at' => now(),
            'worker_pid' => 1234,
            'worker_host' => 'node-test',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ya existe un lease de indexación activo para el ámbito ESTATALES');

        $this->service->indexar(BiometricoAmbito::ESTATALES, $this->tempDir);
    }

    public function test_reanudacion_con_checkpoint_omite_archivos_previos(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', 'foto 1');
        file_put_contents($fotosDir . '/BBBB700101H12345678.JPG', 'foto 2');
        file_put_contents($fotosDir . '/CCCC800202M87654321.JPG', 'foto 3');

        $canonicalTemp = $this->service->getScanner()->validarRutaRaiz($this->tempDir);

        // Simular corrida previa interrumpida en el primer archivo
        $runInterrumpida = BiometricoIndexRun::create([
            'ambito' => 'ESTATALES',
            'estado' => BiometricoIndexRunEstado::INTERRUMPIDA,
            'iniciado_at' => now()->subMinutes(10),
            'finalizado_at' => now()->subMinutes(9),
            'ultima_ruta_procesada' => 'FOTOS/AAAA690916H15136329.JPG',
            'ultimo_checkpoint_id' => 1,
            'metadata' => [
                'root_path' => $canonicalTemp,
                'config_fingerprint' => BiometricIndexingService::calcularFingerprintConfiguracion(BiometricoAmbito::ESTATALES, $canonicalTemp),
            ],
        ]);

        // Reanudar ejecución
        $runReanudada = $this->service->indexar(
            ambito: BiometricoAmbito::ESTATALES,
            rootPath: $this->tempDir,
            resume: true
        );

        $this->assertSame(BiometricoIndexRunEstado::COMPLETADA, $runReanudada->estado);
        // Debe haber omitido el archivo AAAA y procesado únicamente BBBB y CCCC
        $this->assertSame(2, $runReanudada->total_archivos_procesados);
        $this->assertSame(2, $runReanudada->total_archivos_nuevos);
        $this->assertSame($runInterrumpida->uuid, $runReanudada->metadata['reanudado_de_uuid']);
    }

    public function test_lotes_transaccionales_y_actualizacion_de_checkpoints(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', '1');
        file_put_contents($fotosDir . '/BBBB700101H12345678.JPG', '2');
        file_put_contents($fotosDir . '/CCCC800202M87654321.JPG', '3');

        // Tamaño de lote = 1 para forzar múltiples checkpoints
        $run = $this->service->indexar(
            ambito: BiometricoAmbito::ESTATALES,
            rootPath: $this->tempDir,
            chunkSize: 1
        );

        $this->assertSame(3, $run->total_archivos_procesados);
        $this->assertSame(3, $run->ultimo_checkpoint_id);
        $this->assertNotEmpty($run->ultima_ruta_procesada);
        $this->assertSame('FOTOS', $run->ultimo_directorio_procesado);
    }

    public function test_exploracion_completa_detecta_archivos_desaparecidos(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        $path1 = $fotosDir . '/AAAA690916H15136329.JPG';
        $path2 = $fotosDir . '/BBBB700101H12345678.JPG';
        file_put_contents($path1, '1');
        file_put_contents($path2, '2');

        // Primera indexación: ambos registrados
        $this->service->indexar(BiometricoAmbito::ESTATALES, $this->tempDir);
        $this->assertSame(2, BiometricoArchivo::count());

        // Eliminar físicamente el archivo 2
        unlink($path2);

        // Segunda indexación completa
        $run2 = $this->service->indexar(BiometricoAmbito::ESTATALES, $this->tempDir);

        $this->assertSame(1, $run2->total_archivos_desaparecidos);
        $archivoDesaparecido = BiometricoArchivo::where('ruta_relativa', 'FOTOS/BBBB700101H12345678.JPG')->first();
        $this->assertSame(BiometricoEstadoArchivo::DESAPARECIDO, $archivoDesaparecido->estado_archivo);

        $incidencia = BiometricoIncidencia::where('codigo', 'ARCHIVO_DESAPARECIDO')->first();
        $this->assertNotNull($incidencia);
        $this->assertSame(BiometricoIncidenciaTipo::ARCHIVO_DESAPARECIDO, $incidencia->tipo);
    }

    public function test_exploracion_incompleta_con_limite_nunca_marca_desaparecidos(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', '1');
        file_put_contents($fotosDir . '/BBBB700101H12345678.JPG', '2');

        // Registrar ambos
        $this->service->indexar(BiometricoAmbito::ESTATALES, $this->tempDir);

        // Ejecutar exploración parcial con límite = 1
        $runParcial = $this->service->indexar(
            ambito: BiometricoAmbito::ESTATALES,
            rootPath: $this->tempDir,
            limit: 1
        );

        // El segundo archivo no se observó por haber alcanzado el límite, pero NO debe marcarse como desaparecido
        $this->assertSame(0, $runParcial->total_archivos_desaparecidos);
        $archivosDesaparecidos = BiometricoArchivo::where('estado_archivo', BiometricoEstadoArchivo::DESAPARECIDO)->count();
        $this->assertSame(0, $archivosDesaparecidos);
    }

    public function test_vincula_persona_oficial_si_coincide_cuip_exacto(): void
    {
        // Crear persona oficial en catálogo LocPersona
        $persona = new LocPersona();
        $persona->cuip = 'AAAA690916H15136329';
        $persona->nombre = 'JUAN PEREZ LOPEZ';
        $persona->save();

        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', 'bytes_foto');

        $this->service->indexar(BiometricoAmbito::ESTATALES, $this->tempDir);

        $expediente = BiometricoExpediente::where('cuip', 'AAAA690916H15136329')->first();
        $this->assertNotNull($expediente);
        $this->assertSame($persona->id, $expediente->persona_id);
    }
}
