<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\IndexBiometricosCommand;
use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoCategoria;
use App\Enums\BiometricoEstadoArchivo;
use App\Enums\BiometricoIndexRunEstado;
use App\Models\BiometricoArchivo;
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
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BiometricIndexCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bio_cmd_test_' . uniqid();
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

    public function test_01_comando_esta_registrado_y_descubrible_en_artisan(): void
    {
        $commands = Artisan::all();
        $this->assertArrayHasKey('loc:biometricos:indexar', $commands);
        $this->assertInstanceOf(IndexBiometricosCommand::class, $commands['loc:biometricos:indexar']);
    }

    public function test_02_rechazo_de_ambito_obligatorio_ausente(): void
    {
        $this->artisan('loc:biometricos:indexar')
            ->expectsOutputToContain('El parámetro --ambito es obligatorio')
            ->assertExitCode(Command::INVALID);
    }

    public function test_03_rechazo_de_ambito_invalido(): void
    {
        $this->artisan('loc:biometricos:indexar', ['--ambito' => 'FEDERALES'])
            ->expectsOutputToContain("El ámbito institucional 'FEDERALES' no es válido")
            ->assertExitCode(Command::INVALID);
    }

    public function test_04_rechazo_estricto_del_repositorio_institucional_real_biometricos_estatal(): void
    {
        // Intento de apuntar a la ruta del repositorio original institucional
        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => 'C:/Users/Daniel/Documents/Residencias/Bases de datos/Biometricos_Estatal',
        ])
            ->expectsOutputToContain('[BLOQUEO DE SEGURIDAD INSTITUCIONAL]')
            ->expectsOutputToContain("Está estrictamente prohibido indexar o acceder al repositorio físico original 'Biometricos_Estatal'")
            ->expectsOutputToContain('Para ejecutar pruebas controladas, proporcione un directorio sintético temporal')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, BiometricoIndexRun::count());
        $this->assertSame(0, BiometricoIndexLease::count());
        $this->assertSame(0, BiometricoArchivo::count());
    }

    public function test_05_rechazo_de_ruta_inexistente_o_invalida(): void
    {
        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => sys_get_temp_dir() . '/directorio_fantasma_inexistente_' . uniqid(),
        ])
            ->expectsOutputToContain('Error de validación de ruta')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_06_rechazo_de_batch_size_fuera_de_rango(): void
    {
        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => $this->tempDir,
            '--batch-size' => 0,
        ])
            ->expectsOutputToContain('El tamaño de lote (--batch-size=0) debe ser un entero entre 1 y 5000')
            ->assertExitCode(Command::INVALID);

        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => $this->tempDir,
            '--batch-size' => 6000,
        ])
            ->expectsOutputToContain('El tamaño de lote (--batch-size=6000) debe ser un entero entre 1 y 5000')
            ->assertExitCode(Command::INVALID);
    }

    public function test_07_rechazo_de_limit_menor_o_igual_a_cero(): void
    {
        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => $this->tempDir,
            '--limit' => 0,
        ])
            ->expectsOutputToContain('El límite de procesamiento (--limit=0) debe ser un entero positivo mayor a cero')
            ->assertExitCode(Command::INVALID);
    }

    public function test_08_modo_dry_run_simula_sin_persistir_en_base_de_datos(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', 'datos_foto_simulada');

        $writeQueries = [];
        DB::listen(function ($query) use (&$writeQueries) {
            $sql = strtolower(trim($query->sql));
            if (preg_match('/^(insert|update|delete|replace|alter|create|drop)\b/', $sql)) {
                $writeQueries[] = $query->sql;
            }
        });

        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => $this->tempDir,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('SIMULACIÓN (DRY-RUN / Sin escrituras en BD)')
            ->expectsOutputToContain('RESUMEN FINAL DE EJECUCIÓN BIOMÉTRICA')
            ->assertExitCode(Command::SUCCESS);

        // Verificación estricta de ausencia de escrituras SQL (AUD-244-002)
        $this->assertEmpty($writeQueries, 'Se detectaron consultas de escritura SQL durante la simulación dry-run: ' . implode('; ', $writeQueries));

        // Verificación estricta de cero registros persistidos en todos los modelos biométricos (AUD-244-006)
        $this->assertSame(0, BiometricoIndexRun::count(), 'El modo dry-run persistió indebidamente registros en biometrico_index_runs');
        $this->assertSame(0, BiometricoIndexLease::count(), 'El modo dry-run persistió indebidamente leases');
        $this->assertSame(0, BiometricoIncidencia::count(), 'El modo dry-run persistió indebidamente incidencias');
        $this->assertSame(0, BiometricoArchivo::count(), 'El modo dry-run persistió indebidamente archivos');
        $this->assertSame(0, BiometricoExpediente::count(), 'El modo dry-run persistió indebidamente expedientes');
        $this->assertSame(0, BiometricoSlotCandidato::count(), 'El modo dry-run persistió indebidamente slot candidatos');
    }

    public function test_09_ejecucion_controlada_en_directorio_sintetico_persiste_correctamente(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', 'foto_facial_real_sintetica');

        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => $this->tempDir,
            '--force' => true,
        ])
            ->expectsOutputToContain('EJECUCIÓN CONTROLADA (Persistente)')
            ->expectsOutputToContain('Proceso de indexación completado exitosamente')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(1, BiometricoIndexRun::count());
        $this->assertSame(1, BiometricoArchivo::count());
        $this->assertSame(1, BiometricoExpediente::count());

        $archivo = BiometricoArchivo::first();
        $this->assertSame('AAAA690916H15136329', $archivo->cuip_normalizado);
        $this->assertSame(BiometricoCategoria::FOTO, $archivo->categoria);
        $this->assertSame(BiometricoEstadoArchivo::VALIDO, $archivo->estado_archivo);
    }

    public function test_10_consulta_de_estado_solo_lectura_existente_e_inexistente(): void
    {
        // 1. Consulta inexistente
        $this->artisan('loc:biometricos:indexar', ['--status' => 'uuid-inexistente-12345'])
            ->expectsOutputToContain('No se encontró ningún registro de ejecución')
            ->assertExitCode(Command::FAILURE);

        // 2. Consulta existente
        $run = BiometricoIndexRun::create([
            'ambito' => 'ESTATALES',
            'estado' => BiometricoIndexRunEstado::COMPLETADA,
            'iniciado_at' => now()->subMinutes(5),
            'finalizado_at' => now(),
            'duracion_segundos' => 300,
            'total_archivos_detectados' => 10,
            'total_archivos_procesados' => 10,
            'total_archivos_nuevos' => 8,
            'total_archivos_sin_cambios' => 2,
            'total_incidencias' => 1,
            'memoria_pico_mb' => 15.5,
        ]);

        BiometricoIncidencia::create([
            'biometrico_index_run_id' => $run->id,
            'ruta_relativa' => 'FOTOS/test.xyz',
            'nombre_archivo' => 'test.xyz',
            'tipo' => 'nombre_invalido',
            'severidad' => 'media',
            'codigo' => 'EXTENSION_NO_ADMITIDA',
            'ambito' => 'ESTATALES',
            'descripcion' => 'Archivo no admitido de prueba',
            'fecha_deteccion' => now(),
        ]);

        $this->artisan('loc:biometricos:indexar', ['--status' => $run->uuid])
            ->expectsOutputToContain('ESTADO DE EJECUCIÓN BIOMÉTRICA')
            ->expectsOutputToContain($run->uuid)
            ->expectsOutputToContain('ESTATALES')
            ->expectsOutputToContain('completada')
            ->expectsOutputToContain('Archivos Nuevos Registrados')
            ->expectsOutputToContain('RESUMEN DE INCIDENCIAS REGISTRADAS')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_11_cancelacion_segura_de_run_en_ejecucion_libera_lease(): void
    {
        $run = BiometricoIndexRun::create([
            'ambito' => 'ESTATALES',
            'estado' => BiometricoIndexRunEstado::EN_EJECUCION,
            'iniciado_at' => now()->subMinutes(2),
            'ultima_ruta_procesada' => 'FOTOS/AAAA690916H15136329.JPG',
        ]);

        BiometricoIndexLease::create([
            'ambito' => 'ESTATALES',
            'owner_token' => 'worker-en-ejecucion',
            'biometrico_index_run_id' => $run->id,
            'fencing_token' => 1,
            'lease_expires_at' => now()->addMinutes(10),
            'heartbeat_at' => now(),
            'worker_pid' => 1234,
            'worker_host' => 'node-test',
        ]);

        $this->assertSame(1, BiometricoIndexLease::where('ambito', 'ESTATALES')->count());

        $this->artisan('loc:biometricos:indexar', ['--cancel' => $run->uuid])
            ->expectsOutputToContain('ha sido cancelada exitosamente')
            ->expectsOutputToContain('Los leases asociados fueron liberados')
            ->assertExitCode(Command::SUCCESS);

        $run->refresh();
        $this->assertSame(BiometricoIndexRunEstado::CANCELADA, $run->estado);
        $this->assertSame(0, BiometricoIndexLease::where('ambito', 'ESTATALES')->count());
    }

    public function test_12_cancelacion_es_idempotente_si_run_ya_esta_finalizado(): void
    {
        $run = BiometricoIndexRun::create([
            'ambito' => 'ESTATALES',
            'estado' => BiometricoIndexRunEstado::COMPLETADA,
            'iniciado_at' => now()->subMinutes(10),
            'finalizado_at' => now()->subMinutes(5),
        ]);

        $this->artisan('loc:biometricos:indexar', ['--cancel' => (string) $run->id])
            ->expectsOutputToContain('ya se encontraba en estado terminal [completada]')
            ->assertExitCode(Command::SUCCESS);

        $run->refresh();
        $this->assertSame(BiometricoIndexRunEstado::COMPLETADA, $run->estado);
    }

    public function test_13_reanudacion_con_resume_procesa_remanente_sin_omitir_archivos(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', '1');
        file_put_contents($fotosDir . '/BBBB700101H12345678.JPG', '2');
        file_put_contents($fotosDir . '/CCCC800202M87654321.JPG', '3');

        // Corrida 1: limitada a 1 archivo
        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => $this->tempDir,
            '--limit' => 1,
            '--force' => true,
        ])->assertExitCode(Command::SUCCESS);

        $run1 = BiometricoIndexRun::first();
        $this->assertNotNull($run1);

        // Forzar a estado INTERRUMPIDA para simular interrupción
        $run1->estado = BiometricoIndexRunEstado::INTERRUMPIDA;
        $run1->resumen_interrupcion = 'Simulación de interrupción';
        $run1->save();

        // Corrida 2: reanudar
        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => $this->tempDir,
            '--resume' => $run1->uuid,
            '--force' => true,
        ])
            ->expectsOutputToContain('Reanudación          : SÍ (Checkpoint previo)')
            ->expectsOutputToContain('Proceso de indexación completado exitosamente')
            ->assertExitCode(Command::SUCCESS);

        // Se deben haber procesado los 3 archivos en total sin omisiones
        $this->assertSame(3, BiometricoArchivo::count());
    }

    public function test_14_reanudacion_rechaza_fingerprint_incompatible(): void
    {
        $run = BiometricoIndexRun::create([
            'ambito' => 'ESTATALES',
            'estado' => BiometricoIndexRunEstado::INTERRUMPIDA,
            'iniciado_at' => now()->subMinutes(10),
            'ultima_ruta_procesada' => 'FOTOS/AAAA690916H15136329.JPG',
            'metadata' => [
                'root_path' => str_replace('\\', '/', realpath($this->tempDir)),
                'config_fingerprint' => 'hash_fingerprint_falso_o_incompatible',
            ],
        ]);

        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => $this->tempDir,
            '--resume' => $run->uuid,
            '--force' => true,
        ])
            ->expectsOutputToContain('[ERROR DE VALIDACIÓN O CONFIGURACIÓN]')
            ->expectsOutputToContain('el fingerprint de configuración actual no coincide')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_15_rechazo_de_lease_ocupado_sin_crear_runs_huerfanos(): void
    {
        $runPrevio = BiometricoIndexRun::create([
            'ambito' => 'ESTATALES',
            'estado' => BiometricoIndexRunEstado::EN_EJECUCION,
            'iniciado_at' => now(),
        ]);

        BiometricoIndexLease::create([
            'ambito' => 'ESTATALES',
            'owner_token' => 'worker-otro-proceso',
            'biometrico_index_run_id' => $runPrevio->id,
            'fencing_token' => 1,
            'lease_expires_at' => now()->addMinutes(10),
            'heartbeat_at' => now(),
            'worker_pid' => 9999,
            'worker_host' => 'otro-nodo',
        ]);

        $this->assertSame(1, BiometricoIndexRun::count());

        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => $this->tempDir,
            '--force' => true,
        ])
            ->expectsOutputToContain('[CONFLICTO DE CONCURRENCIA O LEASE]')
            ->expectsOutputToContain('Ya existe un lease de indexación activo para el ámbito ESTATALES')
            ->assertExitCode(Command::FAILURE);

        // Ningún run huérfano debe haberse creado
        $this->assertSame(1, BiometricoIndexRun::count());
    }

    public function test_16_fallo_de_worker_antiguo_si_fencing_token_cambia(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', 'foto');

        // Alterar el lease en BD para simular desalojo
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'biometrico_index_leases') && str_contains($query->sql, 'insert')) {
                DB::table('biometrico_index_leases')->update(['fencing_token' => 888]);
            }
        });

        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => $this->tempDir,
            '--force' => true,
        ])
            ->expectsOutputToContain('[CONFLICTO DE CONCURRENCIA O LEASE]')
            ->expectsOutputToContain('perdió el lease de indexación')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_17_ausencia_de_falsos_desaparecidos_ante_interrupcion(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', '1');
        file_put_contents($fotosDir . '/BBBB700101H12345678.JPG', '2');

        // Indexación inicial
        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => $this->tempDir,
            '--force' => true,
        ])->assertExitCode(Command::SUCCESS);

        $this->assertSame(2, BiometricoArchivo::count());

        // Segunda corrida limitada a 1 archivo (simula escaneo parcial / interrupción)
        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => $this->tempDir,
            '--limit' => 1,
            '--force' => true,
        ])->assertExitCode(Command::SUCCESS);

        // El segundo archivo NO debe marcarse como desaparecido
        $desaparecidos = BiometricoArchivo::where('estado_archivo', BiometricoEstadoArchivo::DESAPARECIDO)->count();
        $this->assertSame(0, $desaparecidos);
    }

    public function test_18_aislamiento_entre_estatales_y_prevencion(): void
    {
        // Crear catálogo y persona oficial en ESTATALES
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
            'direccion_general' => 'DIR. SEG. PUB.',
            'activo' => true,
        ]);

        $persona = new LocPersona();
        $persona->cuip = 'AAAA690916H15136329';
        $persona->nombre = 'AGENTE ESTATAL';
        $persona->clave_seguridad = 'SP01';
        $persona->save();

        // Archivo colocado en carpeta de PREVENCION
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', 'foto_estatal_en_prevencion');

        // Ejecutar comando para PREVENCION
        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'PREVENCION',
            '--path' => $this->tempDir,
            '--force' => true,
        ])->assertExitCode(Command::SUCCESS);

        // El archivo debe quedar en cuarentena y NO debe crearse expediente en PREVENCION
        $archivo = BiometricoArchivo::first();
        $this->assertSame(BiometricoEstadoArchivo::CUARENTENA, $archivo->estado_archivo);
        $this->assertSame(0, BiometricoExpediente::where('ambito', 'PREVENCION')->count());
    }

    public function test_19_colision_de_slot_congela_slot_y_registra_candidatos(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', 'foto_uno');

        // Primera indexación
        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => $this->tempDir,
            '--force' => true,
        ])->assertExitCode(Command::SUCCESS);

        $expediente = BiometricoExpediente::first();
        $this->assertNotNull($expediente->foto_archivo_id);

        // Segunda foto distinta para el mismo CUIP en subdirectorio válido
        $subDir = $fotosDir . '/OTRO';
        mkdir($subDir, 0777, true);
        file_put_contents($subDir . '/AAAA690916H15136329.JPG', 'foto_dos_distinta');

        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => $this->tempDir,
            '--force' => true,
        ])->assertExitCode(Command::SUCCESS);

        // El slot en el expediente DEBE CONGELARSE A NULL
        $expediente->refresh();
        $this->assertNull($expediente->foto_archivo_id);
        $this->assertTrue($expediente->tiene_duplicados);
        $this->assertTrue($expediente->tiene_anomalias);
        $this->assertSame('en_aclaracion', $expediente->estado_revision->value ?? $expediente->estado_revision);

        $this->assertSame(2, BiometricoSlotCandidato::where('biometrico_expediente_id', $expediente->id)->count());
    }

    public function test_20_integridad_de_contadores_y_metricas_en_resumen_final(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', '1');
        file_put_contents($fotosDir . '/BBBB700101H12345678.JPG', '2');

        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => $this->tempDir,
            '--force' => true,
        ])
            ->expectsOutputToContain('Archivos Detectados')
            ->expectsOutputToContain('Archivos Procesados')
            ->expectsOutputToContain('Archivos Nuevos')
            ->assertExitCode(Command::SUCCESS);

        $run = BiometricoIndexRun::first();
        $this->assertSame(2, $run->total_archivos_detectados);
        $this->assertSame(2, $run->total_archivos_procesados);
        $this->assertSame(2, $run->total_archivos_nuevos);
        $this->assertSame(0, $run->total_archivos_desaparecidos);
        $this->assertSame(0, $run->total_archivos_invalidos);
        $this->assertSame(BiometricoIndexRunEstado::COMPLETADA, $run->estado);
    }

    public function test_21_ejecucion_no_interactiva_sin_force_es_rechazada(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', '1');

        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => $this->tempDir,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('[ERROR DE AUTORIZACIÓN] En entornos no interactivos')
            ->expectsOutputToContain('Para ejecutar una simulación segura sin persistencia, agregue la opción --dry-run.')
            ->assertExitCode(Command::INVALID);

        $this->assertSame(0, BiometricoIndexRun::count(), 'No debe crearse ningún run sin autorización');
        $this->assertSame(0, BiometricoIndexLease::count(), 'No debe adquirirse ningún lease sin autorización');
    }

    public function test_22_force_no_puede_evadir_bloqueo_de_ruta_institucional(): void
    {
        // Pasar --force hacia la ruta institucional no debe permitir eludir el bloqueo
        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => 'C:/Users/Daniel/Documents/Residencias/Bases de datos/Biometricos_Estatal',
            '--force' => true,
        ])
            ->expectsOutputToContain('[BLOQUEO DE SEGURIDAD INSTITUCIONAL]')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, BiometricoIndexRun::count());
        $this->assertSame(0, BiometricoIndexLease::count());
    }

    public function test_23_dry_run_funciona_sin_tablas_biometricas_en_bd(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', '1');

        // Eliminar las tablas biométricas para simular un entorno no migrado (como MariaDB 3308)
        \Illuminate\Support\Facades\Schema::dropIfExists('biometrico_slot_candidatos');
        \Illuminate\Support\Facades\Schema::dropIfExists('biometrico_incidencias');
        \Illuminate\Support\Facades\Schema::dropIfExists('biometrico_archivos');
        \Illuminate\Support\Facades\Schema::dropIfExists('biometrico_expedientes');
        \Illuminate\Support\Facades\Schema::dropIfExists('biometrico_index_leases');
        \Illuminate\Support\Facades\Schema::dropIfExists('biometrico_index_runs');

        // El modo dry-run debe operar 100% en memoria y terminar con éxito sin lanzar excepciones PDO
        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => $this->tempDir,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('SIMULACIÓN (DRY-RUN / Sin escrituras en BD)')
            ->expectsOutputToContain('RESUMEN FINAL DE EJECUCIÓN BIOMÉTRICA')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_24_esquema_incompleto_es_detectado_y_rechazado_antes_de_ejecutar(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', '1');

        // Eliminar una tabla obligatoria para simular migración parcial
        \Illuminate\Support\Facades\Schema::dropIfExists('biometrico_slot_candidatos');

        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => $this->tempDir,
            '--force' => true,
        ])
            ->expectsOutputToContain('[ERROR DE ESQUEMA EN BASE DE DATOS]')
            ->expectsOutputToContain("Falta la tabla obligatoria: 'biometrico_slot_candidatos'")
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, BiometricoIndexRun::count(), 'No debe crearse ningún run si el esquema está incompleto');
    }

    public function test_25_deteccion_fisica_de_alias_y_unidades_virtuales(): void
    {
        // Directorio sintético simulando repositorio institucional protegido
        $syntheticProtected = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'protected_target_' . uniqid();
        mkdir($syntheticProtected, 0777, true);

        // Subdirectorio dentro del protegido
        $syntheticSubdir = $syntheticProtected . DIRECTORY_SEPARATOR . 'ESTATALES';
        mkdir($syntheticSubdir, 0777, true);

        /** @var IndexBiometricosCommand $cmd */
        $cmd = $this->app->make(IndexBiometricosCommand::class);
        $cmd->setProtectedRootOverride($syntheticProtected);

        // 1. Detección física directa
        $resDirect = $cmd->validarRutaSegura($syntheticProtected);
        $this->assertFalse($resDirect['valido']);
        $this->assertStringContainsString('[BLOQUEO DE SEGURIDAD INSTITUCIONAL]', $resDirect['mensaje']);

        // 2. Detección física de subdirectorio anidado
        $resSub = $cmd->validarRutaSegura($syntheticSubdir);
        $this->assertFalse($resSub['valido']);
        $this->assertStringContainsString('coincide con o está contenida dentro del repositorio protegido', $resSub['mensaje']);

        // 3. Directorio completamente externo debe ser aceptado
        $resExternal = $cmd->validarRutaSegura($this->tempDir);
        $this->assertTrue($resExternal['valido']);

        @rmdir($syntheticSubdir);
        @rmdir($syntheticProtected);
    }

    public function test_26_ejecucion_interactiva_sin_force_cancelada_por_usuario(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', '1');

        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => $this->tempDir,
        ])
            ->expectsConfirmation('¿Desea iniciar la ejecución controlada sobre el directorio especificado?', 'no')
            ->expectsOutputToContain('Operación cancelada por el usuario.')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(0, BiometricoIndexRun::count(), 'No debe crearse ningún run si el operador cancela');
        $this->assertSame(0, BiometricoIndexLease::count(), 'No debe adquirirse ningún lease si el operador cancela');
    }

    public function test_27_ejecucion_interactiva_sin_force_confirmada_por_usuario(): void
    {
        $fotosDir = $this->tempDir . '/FOTOS';
        mkdir($fotosDir, 0777, true);
        file_put_contents($fotosDir . '/AAAA690916H15136329.JPG', '1');

        $this->artisan('loc:biometricos:indexar', [
            '--ambito' => 'ESTATALES',
            '--path' => $this->tempDir,
        ])
            ->expectsConfirmation('¿Desea iniciar la ejecución controlada sobre el directorio especificado?', 'yes')
            ->expectsOutputToContain('EJECUCIÓN CONTROLADA (Persistente)')
            ->expectsOutputToContain('Proceso de indexación completado exitosamente')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(1, BiometricoIndexRun::count(), 'Debe crearse un run cuando el operador confirma');
    }
}
