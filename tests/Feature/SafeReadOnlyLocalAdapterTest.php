<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoCategoria;
use App\Enums\BiometricoEstadoArchivo;
use App\Exceptions\BiometricoReadOnlyException;
use App\Models\BiometricoArchivo;
use App\Models\BiometricoAuditoria;
use App\Models\BiometricoExpediente;
use App\Models\User;
use App\Services\BiometricDeliveryService;
use App\Support\Storage\SafeReadOnlyLocalAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tests\TestCase;

class SafeReadOnlyLocalAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected string $tempDir;
    protected SafeReadOnlyLocalAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        // Crear directorio temporal estrictamente aislado para pruebas
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_bio_safe_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'subcarpeta', 0777, true);

        // Crear fixtures temporales ficticios
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'test_foto.jpg', 'FAKE_JPEG_BINARY_DATA_FOR_TESTING');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'subcarpeta' . DIRECTORY_SEPARATOR . 'test_firma.png', 'FAKE_PNG_SIGNATURE_BYTES');

        // Instanciar adaptador apuntando a la carpeta temporal aislada
        $this->adapter = new SafeReadOnlyLocalAdapter($this->tempDir);

        // Configurar el disco 'biometricos' de Laravel para que en este entorno apunte a la carpeta temporal
        config(['filesystems.disks.biometricos.root' => $this->tempDir]);
    }

    protected function tearDown(): void
    {
        // Limpieza exhaustiva de fixtures temporales
        $this->deleteDirectoryRecursive($this->tempDir);

        parent::tearDown();
    }

    protected function deleteDirectoryRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectoryRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    // =========================================================================
    // 8.1. PRUEBAS DE LECTURA Y CONSULTA
    // =========================================================================

    public function test_read_retorna_contenido_correcto(): void
    {
        $content = $this->adapter->read('test_foto.jpg');
        $this->assertSame('FAKE_JPEG_BINARY_DATA_FOR_TESTING', $content);
    }

    public function test_read_stream_abre_recurso_valido(): void
    {
        $stream = $this->adapter->readStream('test_foto.jpg');
        $this->assertIsResource($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $this->assertSame('FAKE_JPEG_BINARY_DATA_FOR_TESTING', $content);
    }

    public function test_file_exists_detecta_archivos_existentes_e_inexistentes(): void
    {
        $this->assertTrue($this->adapter->fileExists('test_foto.jpg'));
        $this->assertTrue($this->adapter->fileExists('subcarpeta/test_firma.png'));
        $this->assertFalse($this->adapter->fileExists('archivo_inexistente.jpg'));
    }

    public function test_directory_exists_detecta_directorios(): void
    {
        $this->assertTrue($this->adapter->directoryExists('subcarpeta'));
        $this->assertFalse($this->adapter->directoryExists('directorio_inexistente'));
    }

    public function test_file_size_devuelve_el_tamano_exacto(): void
    {
        $attrs = $this->adapter->fileSize('test_foto.jpg');
        $this->assertSame(strlen('FAKE_JPEG_BINARY_DATA_FOR_TESTING'), $attrs->fileSize());
    }

    public function test_last_modified_devuelve_timestamp_valido(): void
    {
        $attrs = $this->adapter->lastModified('test_foto.jpg');
        $this->assertGreaterThan(0, $attrs->lastModified());
    }

    public function test_list_contents_enumera_archivos_sin_modificar(): void
    {
        $contents = iterator_to_array($this->adapter->listContents('', false));
        $this->assertNotEmpty($contents);

        $paths = array_map(fn ($item) => $item->path(), $contents);
        $this->assertContains('test_foto.jpg', $paths);
        $this->assertContains('subcarpeta', $paths);
    }

    // =========================================================================
    // 8.2. PRUEBAS DE BLOQUEO DE OPERACIONES MUTADORAS
    // =========================================================================

    public function test_write_lanza_excepcion_de_solo_lectura(): void
    {
        $this->expectException(BiometricoReadOnlyException::class);
        $this->expectExceptionMessage('write');

        $this->adapter->write('nuevo_archivo.txt', 'contenido', new Config());
    }

    public function test_write_stream_lanza_excepcion_de_solo_lectura(): void
    {
        $stream = fopen('php://memory', 'r+');

        try {
            $this->expectException(BiometricoReadOnlyException::class);
            $this->expectExceptionMessage('writeStream');

            $this->adapter->writeStream('nuevo_archivo.txt', $stream, new Config());
        } finally {
            fclose($stream);
        }
    }

    public function test_delete_lanza_excepcion_y_preserva_archivo(): void
    {
        try {
            $this->adapter->delete('test_foto.jpg');
            $this->fail('Se esperaba BiometricoReadOnlyException al invocar delete()');
        } catch (BiometricoReadOnlyException $e) {
            $this->assertStringContainsString('delete', $e->getMessage());
        }

        // El archivo original de prueba debe permanecer intacto
        $this->assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'test_foto.jpg');
        $this->assertSame('FAKE_JPEG_BINARY_DATA_FOR_TESTING', file_get_contents($this->tempDir . DIRECTORY_SEPARATOR . 'test_foto.jpg'));
    }

    public function test_delete_directory_lanza_excepcion_y_preserva_carpeta(): void
    {
        try {
            $this->adapter->deleteDirectory('subcarpeta');
            $this->fail('Se esperaba BiometricoReadOnlyException al invocar deleteDirectory()');
        } catch (BiometricoReadOnlyException $e) {
            $this->assertStringContainsString('deleteDirectory', $e->getMessage());
        }

        $this->assertDirectoryExists($this->tempDir . DIRECTORY_SEPARATOR . 'subcarpeta');
    }

    public function test_create_directory_lanza_excepcion(): void
    {
        $this->expectException(BiometricoReadOnlyException::class);
        $this->expectExceptionMessage('createDirectory');

        $this->adapter->createDirectory('nueva_carpeta', new Config());
    }

    public function test_set_visibility_lanza_excepcion(): void
    {
        $this->expectException(BiometricoReadOnlyException::class);
        $this->expectExceptionMessage('setVisibility');

        $this->adapter->setVisibility('test_foto.jpg', 'public');
    }

    public function test_move_lanza_excepcion_y_preserva_origen(): void
    {
        try {
            $this->adapter->move('test_foto.jpg', 'test_foto_movida.jpg', new Config());
            $this->fail('Se esperaba BiometricoReadOnlyException al invocar move()');
        } catch (BiometricoReadOnlyException $e) {
            $this->assertStringContainsString('move', $e->getMessage());
        }

        $this->assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'test_foto.jpg');
        $this->assertFileDoesNotExist($this->tempDir . DIRECTORY_SEPARATOR . 'test_foto_movida.jpg');
    }

    public function test_copy_lanza_excepcion_y_no_crea_copia(): void
    {
        try {
            $this->adapter->copy('test_foto.jpg', 'test_foto_copia.jpg', new Config());
            $this->fail('Se esperaba BiometricoReadOnlyException al invocar copy()');
        } catch (BiometricoReadOnlyException $e) {
            $this->assertStringContainsString('copy', $e->getMessage());
        }

        $this->assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . 'test_foto.jpg');
        $this->assertFileDoesNotExist($this->tempDir . DIRECTORY_SEPARATOR . 'test_foto_copia.jpg');
    }

    public function test_storage_put_mediante_fachada_laravel_es_bloqueado(): void
    {
        $disk = Storage::disk('biometricos');

        $this->expectException(BiometricoReadOnlyException::class);
        $disk->put('nuevo_archivo.txt', 'mutacion_prohibida');
    }

    // =========================================================================
    // 8.3. PRUEBAS DE SEGURIDAD DE RUTAS Y ANTI-PATH TRAVERSAL
    // =========================================================================

    public function test_rechaza_segmentos_de_retroceso_punto_punto(): void
    {
        $insecure = [
            '../archivo.jpg',
            '../../.env',
            'subcarpeta/../../test.txt',
            'subcarpeta/../subcarpeta/../../etc/passwd',
        ];

        foreach ($insecure as $path) {
            try {
                $this->adapter->read($path);
                $this->fail("Ruta insegura no detectada: {$path}");
            } catch (BiometricoReadOnlyException $e) {
                $this->assertStringContainsString('Segmento de retroceso ".." detectado', $e->getMessage());
            }
        }
    }

    public function test_rechaza_rutas_con_bytes_nulos(): void
    {
        $this->expectException(BiometricoReadOnlyException::class);
        $this->expectExceptionMessage('Byte nulo detectado');

        $this->adapter->read("test_foto.jpg\0.png");
    }

    public function test_rechaza_rutas_absolutas_windows_y_unix(): void
    {
        $absolutes = [
            'C:/Windows/win.ini',
            'C:\\Windows\\System32\\drivers\\etc\\hosts',
            'D:/test.dat',
            '/etc/shadow',
            '/var/www/html/.env',
        ];

        foreach ($absolutes as $path) {
            try {
                $this->adapter->read($path);
                $this->fail("Ruta absoluta no detectada: {$path}");
            } catch (BiometricoReadOnlyException $e) {
                $this->assertStringContainsString('Ruta absoluta no permitida', $e->getMessage());
            }
        }
    }

    public function test_rechaza_esquemas_y_protocolos_uri(): void
    {
        $schemes = [
            'file:///C:/test.txt',
            'php://filter/read=convert.base64-encode/resource=index.php',
            'http://malicious.test/exploit.jpg',
        ];

        foreach ($schemes as $uri) {
            try {
                $this->adapter->read($uri);
                $this->fail("Esquema no detectado: {$uri}");
            } catch (BiometricoReadOnlyException $e) {
                $this->assertStringContainsString('Esquema o flujo no permitido', $e->getMessage());
            }
        }
    }

    // =========================================================================
    // 8.4. PRUEBAS DE INTEGRACIÓN DEL SERVICIO BiometricDeliveryService
    // =========================================================================

    protected function crearUsuario(string $role, bool $localDemo = false): User
    {
        $user = new User();
        $user->name = 'Usuario Prueba ' . ucfirst($role);
        $user->email = 'test_' . $role . '_' . uniqid() . '@example.com';
        $user->password = bcrypt('secret123');
        $user->role = $role;
        $user->save();

        if ($localDemo) {
            $user->setAttribute('local_demo_access', true);
        }

        return $user;
    }

    protected function crearArchivoBiometrico(array $overrides = []): BiometricoArchivo
    {
        return BiometricoArchivo::create(array_merge([
            'ambito' => BiometricoAmbito::ESTATALES,
            'categoria' => BiometricoCategoria::FOTO,
            'ruta_relativa' => 'test_foto.jpg',
            'nombre_archivo' => 'test_foto.jpg',
            'extension' => 'jpg',
            'tamano_bytes' => strlen('FAKE_JPEG_BINARY_DATA_FOR_TESTING'),
            'formato_real' => 'JPEG',
            'mime_type' => 'image/jpeg',
            'sha256' => hash('sha256', 'FAKE_JPEG_BINARY_DATA_FOR_TESTING'),
            'cuip_detectado' => 'TEST1234567890123',
            'cuip_normalizado' => 'TEST1234567890123',
            'estado_archivo' => BiometricoEstadoArchivo::VALIDO,
            'es_vigente' => true,
            'version_secuencia' => 1,
        ], $overrides));
    }

    protected function crearExpediente(BiometricoArchivo $archivo, array $overrides = []): BiometricoExpediente
    {
        $columna = $archivo->categoria?->columnaExpediente() ?? 'foto_archivo_id';

        return BiometricoExpediente::create(array_merge([
            'cuip' => $archivo->cuip_normalizado,
            'ambito' => $archivo->ambito,
            $columna => $archivo->id,
            'total_biometricos' => 1,
            'es_completo' => false,
        ], $overrides));
    }

    public function test_servicio_rechaza_usuario_no_autenticado(): void
    {
        $service = new BiometricDeliveryService();
        $archivo = $this->crearArchivoBiometrico();
        $expediente = $this->crearExpediente($archivo);
        $actorNoAutenticado = new User(); // Sin persistir, id null

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('Usuario no autenticado');

        $service->streamArchivo($archivo, $actorNoAutenticado, Request::create('/test'), $expediente);
    }

    public function test_servicio_rechaza_usuario_sin_permiso_en_gate(): void
    {
        $service = new BiometricDeliveryService();
        $archivo = $this->crearArchivoBiometrico();
        $expediente = $this->crearExpediente($archivo);
        $actorSinPermiso = $this->crearUsuario('consulta_basica');

        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('No cuenta con autorización');

        $service->streamArchivo($archivo, $actorSinPermiso, Request::create('/test'), $expediente);
    }

    public function test_servicio_rechaza_archivo_no_vigente(): void
    {
        $service = new BiometricDeliveryService();
        $archivo = $this->crearArchivoBiometrico(['es_vigente' => false]);
        $expediente = $this->crearExpediente($archivo);
        $actorAdmin = $this->crearUsuario('admin');

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('no se encuentra vigente');

        $service->streamArchivo($archivo, $actorAdmin, Request::create('/test'), $expediente);
    }

    public function test_servicio_rechaza_archivo_con_estado_tecnico_invalido(): void
    {
        $service = new BiometricDeliveryService();
        $archivo = $this->crearArchivoBiometrico(['estado_archivo' => BiometricoEstadoArchivo::INVALIDO]);
        $expediente = $this->crearExpediente($archivo);
        $actorAdmin = $this->crearUsuario('admin');

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('no posee un estado técnico válido');

        $service->streamArchivo($archivo, $actorAdmin, Request::create('/test'), $expediente);
    }

    public function test_servicio_rechaza_discrepancia_de_cuip_o_ambito_con_expediente(): void
    {
        $service = new BiometricDeliveryService();
        $archivo = $this->crearArchivoBiometrico();
        $actorAdmin = $this->crearUsuario('admin');

        $expedienteDiscrepante = BiometricoExpediente::create([
            'cuip' => 'DIFERENTE999999999',
            'ambito' => BiometricoAmbito::ESTATALES,
            'foto_archivo_id' => $archivo->id,
        ]);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Discrepancia de CUIP');

        $service->streamArchivo($archivo, $actorAdmin, Request::create('/test'), $expedienteDiscrepante);
    }

    public function test_servicio_rechaza_archivo_inexistente_fisicamente(): void
    {
        $service = new BiometricDeliveryService();
        $archivo = $this->crearArchivoBiometrico(['ruta_relativa' => 'no_existe_en_disco.jpg']);
        $expediente = $this->crearExpediente($archivo);
        $actorAdmin = $this->crearUsuario('admin');

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('no existe físicamente');

        $service->streamArchivo($archivo, $actorAdmin, Request::create('/test'), $expediente);
    }

    public function test_servicio_rechaza_ruta_insegura_en_modelo_archivo(): void
    {
        $service = new BiometricDeliveryService();
        $archivo = $this->crearArchivoBiometrico(['ruta_relativa' => '../../.env']);
        $expediente = $this->crearExpediente($archivo);
        $actorAdmin = $this->crearUsuario('admin');

        $this->expectException(BiometricoReadOnlyException::class);
        $this->expectExceptionMessage('Segmento de retroceso ".." detectado');

        $service->streamArchivo($archivo, $actorAdmin, Request::create('/test'), $expediente);
    }

    public function test_servicio_entrega_streaming_exitoso_con_auditoria_y_cabeceras(): void
    {
        $service = new BiometricDeliveryService();
        $archivo = $this->crearArchivoBiometrico();
        $expediente = $this->crearExpediente($archivo);
        $actorSupervisor = $this->crearUsuario('supervisor');

        $request = Request::create('/impresion/biometricos/stream', 'GET', [], [], [], [
            'REMOTE_ADDR' => '192.168.1.50',
            'HTTP_USER_AGENT' => 'TestBrowser/1.0',
        ]);

        $response = $service->streamArchivo($archivo, $actorSupervisor, $request, $expediente);

        // 1. Validar que la respuesta sea StreamedResponse con código 200
        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        // 2. Validar cabeceras HTTP de protección anti-caché
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame((string) strlen('FAKE_JPEG_BINARY_DATA_FOR_TESTING'), $response->headers->get('Content-Length'));

        // 3. Capturar el streaming binario emitido
        ob_start();
        $response->sendContent();
        $output = ob_get_clean();
        $this->assertSame('FAKE_JPEG_BINARY_DATA_FOR_TESTING', $output);

        // 4. Validar que se haya registrado la auditoría pericial con snapshots inmutables
        $auditoria = BiometricoAuditoria::latest('id')->first();
        $this->assertNotNull($auditoria, 'La auditoría debió ser creada');
        $this->assertSame('streaming_biometrico', $auditoria->evento);
        $this->assertSame($actorSupervisor->id, $auditoria->user_id);
        $this->assertSame($actorSupervisor->id, $auditoria->actor_user_id_historico);
        $this->assertSame($actorSupervisor->email, $auditoria->actor_email_historico);
        $this->assertSame('TEST1234567890123', $auditoria->cuip_snapshot);
        $this->assertSame('ESTATALES', $auditoria->ambito_snapshot);
        $this->assertSame('foto', $auditoria->tipo_biometrico_snapshot);
        $this->assertSame('test_foto.jpg', $auditoria->ruta_relativa_snapshot);
        $this->assertSame(hash('sha256', 'FAKE_JPEG_BINARY_DATA_FOR_TESTING'), $auditoria->sha256_snapshot);
        $this->assertSame('192.168.1.50', $auditoria->ip_address);
        $this->assertSame('TestBrowser/1.0', $auditoria->user_agent);
        $this->assertSame($expediente->id, $auditoria->biometrico_expediente_id);
    }

    // =========================================================================
    // 8.5. PRUEBAS DE VALIDACIÓN DE RAÍZ EN EL ADAPTADOR (C-01)
    // =========================================================================

    public function test_constructor_rechaza_raiz_vacia_o_solo_espacios(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no puede estar vacía');

        new SafeReadOnlyLocalAdapter('');
    }

    public function test_constructor_rechaza_raiz_con_espacios_en_blanco(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no puede estar vacía');

        new SafeReadOnlyLocalAdapter('     ');
    }

    public function test_constructor_rechaza_bytes_nulos_en_raiz(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('caracteres nulos no permitidos');

        new SafeReadOnlyLocalAdapter($this->tempDir . "\0" . DIRECTORY_SEPARATOR . 'extra');
    }

    public function test_constructor_rechaza_rutas_relativas(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('debe ser una ruta absoluta');

        new SafeReadOnlyLocalAdapter('storage/biometricos');
    }

    public function test_constructor_rechaza_segmentos_de_traversal_en_raiz(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('segmentos relativos');

        new SafeReadOnlyLocalAdapter($this->tempDir . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . basename($this->tempDir));
    }

    public function test_constructor_rechaza_directorio_inexistente(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no existe físicamente');

        new SafeReadOnlyLocalAdapter('C:' . DIRECTORY_SEPARATOR . 'directorio_inexistente_' . uniqid());
    }

    public function test_constructor_rechaza_archivo_regular_como_raiz(): void
    {
        $regularFile = $this->tempDir . DIRECTORY_SEPARATOR . 'archivo_regular.txt';
        file_put_contents($regularFile, 'contenido_de_prueba');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no es un directorio');

        new SafeReadOnlyLocalAdapter($regularFile);
    }

    public function test_constructor_acepta_directorio_absoluto_valido_y_canonico(): void
    {
        $adapter = new SafeReadOnlyLocalAdapter($this->tempDir);
        $expectedCanonical = rtrim(str_replace('\\', '/', realpath($this->tempDir)), '/');

        $this->assertSame($expectedCanonical, $adapter->getCanonicalRoot());
    }

    public function test_constructor_acepta_directorio_con_barras_inclinadas_estilo_unix(): void
    {
        $forwardSlashPath = str_replace('\\', '/', $this->tempDir);
        $adapter = new SafeReadOnlyLocalAdapter($forwardSlashPath);
        $expectedCanonical = rtrim(str_replace('\\', '/', realpath($this->tempDir)), '/');

        $this->assertSame($expectedCanonical, $adapter->getCanonicalRoot());
    }

    public function test_constructor_rechaza_enlace_simbolico_o_junction_externo(): void
    {
        $targetDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'target_junc_' . uniqid();
        mkdir($targetDir, 0777, true);
        $junctionDir = $this->tempDir . DIRECTORY_SEPARATOR . 'junc_externo';

        if (PHP_OS_FAMILY === 'Windows') {
            exec('cmd /c mklink /J "' . $junctionDir . '" "' . $targetDir . '" 2>&1', $output, $rc);
            if ($rc !== 0) {
                @rmdir($targetDir);
                $this->markTestSkipped('No fue posible crear junction point en este entorno de Windows.');
            }
        } else {
            symlink($targetDir, $junctionDir);
        }

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/(enlace|reanálisis|redirección|junction)/i');

            new SafeReadOnlyLocalAdapter($junctionDir);
        } finally {
            if (PHP_OS_FAMILY === 'Windows') {
                @rmdir($junctionDir);
            } else {
                @unlink($junctionDir);
            }
            @rmdir($targetDir);
        }
    }

    public function test_disco_biometricos_falla_si_configuracion_raiz_esta_vacia(): void
    {
        config(['filesystems.disks.biometricos.root' => null]);
        Storage::forgetDisk('biometricos');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requiere una ruta de almacenamiento institucional válida');

        Storage::disk('biometricos');
    }

    // =========================================================================
    // 8.6. PRUEBAS DE VINCULACIÓN OBLIGATORIA Y PERTENENCIA EXPEDIENTE-ARCHIVO (C-02)
    // =========================================================================

    public function test_servicio_rechaza_expediente_no_persistido(): void
    {
        $service = new BiometricDeliveryService();
        $archivo = $this->crearArchivoBiometrico();
        $actorAdmin = $this->crearUsuario('admin');

        $expedienteNoPersistido = new BiometricoExpediente([
            'cuip' => $archivo->cuip_normalizado,
            'ambito' => $archivo->ambito,
            'foto_archivo_id' => $archivo->id,
        ]); // sin guardar, id es null

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('no es válido o no ha sido persistido');

        $service->streamArchivo($archivo, $actorAdmin, Request::create('/test'), $expedienteNoPersistido);
    }

    public function test_servicio_rechaza_expediente_con_ambito_no_resuelto(): void
    {
        $service = new BiometricDeliveryService();
        $archivo = $this->crearArchivoBiometrico();
        $actorAdmin = $this->crearUsuario('admin');

        $expedienteNoResuelto = new BiometricoExpediente();
        $expedienteNoResuelto->id = 99999;
        $expedienteNoResuelto->exists = true;
        $expedienteNoResuelto->cuip = $archivo->cuip_normalizado;
        $expedienteNoResuelto->ambito = BiometricoAmbito::NO_RESUELTO;
        $expedienteNoResuelto->foto_archivo_id = $archivo->id;

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('ámbito institucional definitivo');

        $service->streamArchivo($archivo, $actorAdmin, Request::create('/test'), $expedienteNoResuelto);
    }

    public function test_servicio_rechaza_discrepancia_de_ambito_entre_archivo_y_expediente(): void
    {
        $service = new BiometricDeliveryService();
        $archivo = $this->crearArchivoBiometrico(['ambito' => BiometricoAmbito::ESTATALES]);
        $actorAdmin = $this->crearUsuario('admin');

        $expedientePrevencion = BiometricoExpediente::create([
            'cuip' => $archivo->cuip_normalizado,
            'ambito' => BiometricoAmbito::PREVENCION,
            'foto_archivo_id' => $archivo->id,
        ]);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Discrepancia de ámbito');

        $service->streamArchivo($archivo, $actorAdmin, Request::create('/test'), $expedientePrevencion);
    }

    public function test_servicio_rechaza_archivo_sin_categoria_institucional_valida(): void
    {
        $service = new BiometricDeliveryService();
        $archivo = $this->crearArchivoBiometrico();
        $expediente = $this->crearExpediente($archivo);
        $actorAdmin = $this->crearUsuario('admin');

        // Forzar categoria a null en memoria
        $archivo->setAttribute('categoria', null);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('categoría institucional válida');

        $service->streamArchivo($archivo, $actorAdmin, Request::create('/test'), $expediente);
    }

    public function test_servicio_rechaza_archivo_que_no_pertenece_al_expediente(): void
    {
        $service = new BiometricDeliveryService();
        $archivoSolicitado = $this->crearArchivoBiometrico();
        $otroArchivo = $this->crearArchivoBiometrico(['ruta_relativa' => 'subcarpeta/test_firma.png']);
        $actorAdmin = $this->crearUsuario('admin');

        // Expediente tiene vinculado a $otroArchivo en foto_archivo_id, NO a $archivoSolicitado
        $expediente = BiometricoExpediente::create([
            'cuip' => $archivoSolicitado->cuip_normalizado,
            'ambito' => $archivoSolicitado->ambito,
            'foto_archivo_id' => $otroArchivo->id,
        ]);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('no corresponde al archivo registrado');

        $service->streamArchivo($archivoSolicitado, $actorAdmin, Request::create('/test'), $expediente);
    }

    public function test_servicio_rechaza_archivo_asignado_a_columna_de_otra_categoria(): void
    {
        $service = new BiometricDeliveryService();
        // Archivo es de categoría FIRMA
        $archivoFirma = $this->crearArchivoBiometrico([
            'categoria' => BiometricoCategoria::FIRMA,
            'ruta_relativa' => 'subcarpeta/test_firma.png',
        ]);
        $actorAdmin = $this->crearUsuario('admin');

        // En el expediente, se colocó erróneamente en foto_archivo_id, dejando firma_archivo_id nula
        $expedienteInconsistente = BiometricoExpediente::create([
            'cuip' => $archivoFirma->cuip_normalizado,
            'ambito' => $archivoFirma->ambito,
            'foto_archivo_id' => $archivoFirma->id,
            'firma_archivo_id' => null,
        ]);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage("no tiene ningún archivo vinculado en la posición 'firma'");

        $service->streamArchivo($archivoFirma, $actorAdmin, Request::create('/test'), $expedienteInconsistente);
    }

    public function test_servicio_entrega_las_cuatro_posiciones_biometricas_correctamente(): void
    {
        $service = new BiometricDeliveryService();
        $actorSupervisor = $this->crearUsuario('supervisor');

        // Crear fixtures físicos para cada una de las 4 categorías
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'pulgar_der.wsq', 'FAKE_WSQ_PULGAR_DER');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'pulgar_izq.wsq', 'FAKE_WSQ_PULGAR_IZQ');

        $categoriasConfig = [
            BiometricoCategoria::FOTO->value => [
                'categoria' => BiometricoCategoria::FOTO,
                'ruta' => 'test_foto.jpg',
                'mime' => 'image/jpeg',
                'columna' => 'foto_archivo_id',
            ],
            BiometricoCategoria::FIRMA->value => [
                'categoria' => BiometricoCategoria::FIRMA,
                'ruta' => 'subcarpeta/test_firma.png',
                'mime' => 'image/png',
                'columna' => 'firma_archivo_id',
            ],
            BiometricoCategoria::PULGAR_DERECHO->value => [
                'categoria' => BiometricoCategoria::PULGAR_DERECHO,
                'ruta' => 'pulgar_der.wsq',
                'mime' => 'image/x-wsq',
                'columna' => 'pulgar_derecho_archivo_id',
            ],
            BiometricoCategoria::PULGAR_IZQUIERDO->value => [
                'categoria' => BiometricoCategoria::PULGAR_IZQUIERDO,
                'ruta' => 'pulgar_izq.wsq',
                'mime' => 'image/x-wsq',
                'columna' => 'pulgar_izquierdo_archivo_id',
            ],
        ];

        foreach ($categoriasConfig as $key => $cfg) {
            $cuip = substr('CUIP' . strtoupper($key) . '1234567890', 0, 18);
            $archivo = $this->crearArchivoBiometrico([
                'categoria' => $cfg['categoria'],
                'ruta_relativa' => $cfg['ruta'],
                'mime_type' => $cfg['mime'],
                'cuip_detectado' => $cuip,
                'cuip_normalizado' => $cuip,
            ]);

            $expediente = BiometricoExpediente::create([
                'cuip' => $archivo->cuip_normalizado,
                'ambito' => $archivo->ambito,
                $cfg['columna'] => $archivo->id,
            ]);

            $response = $service->streamArchivo($archivo, $actorSupervisor, Request::create('/stream'), $expediente);
            $this->assertSame(200, $response->getStatusCode(), "Falla al entregar categoría {$cfg['categoria']->value}");
        }
    }

    // =========================================================================
    // 8.7. PRUEBAS DE AUTORIZACIÓN EN GATE view-biometrics (C-03)
    // =========================================================================

    public function test_gate_permite_acceso_a_los_cuatro_roles_institucionales(): void
    {
        $rolesPermitidos = ['superadmin', 'admin', 'supervisor', 'credencializador'];

        foreach ($rolesPermitidos as $role) {
            $user = $this->crearUsuario($role);
            $this->assertTrue(
                Gate::forUser($user)->allows('view-biometrics'),
                "El rol institucional '{$role}' debió ser autorizado en la Gate view-biometrics."
            );
        }
    }

    public function test_gate_deniega_acceso_a_usuarios_demo_local(): void
    {
        // Usuario con flag local_demo_access
        $demoUserConFlag = $this->crearUsuario('admin', localDemo: true);
        $this->assertFalse(
            Gate::forUser($demoUserConFlag)->allows('view-biometrics'),
            'Un usuario con local_demo_access debe ser bloqueado en view-biometrics.'
        );

        // Usuario con rol 'local_demo'
        $demoUserRol = $this->crearUsuario('local_demo');
        $this->assertFalse(
            Gate::forUser($demoUserRol)->allows('view-biometrics'),
            "Un usuario con rol 'local_demo' debe ser bloqueado en view-biometrics."
        );
    }

    public function test_gate_deniega_acceso_a_roles_no_autorizados_y_desconocidos(): void
    {
        $rolesNoAutorizados = ['operador', 'capturista', 'invitado', 'auditor_externo', 'consulta'];

        foreach ($rolesNoAutorizados as $role) {
            $user = $this->crearUsuario($role);
            $this->assertFalse(
                Gate::forUser($user)->allows('view-biometrics'),
                "El rol '{$role}' no debe tener acceso a view-biometrics."
            );
        }

        // Usuario con rol null
        $userSinRol = $this->crearUsuario('sin_rol');
        $userSinRol->role = null;
        $userSinRol->save();

        $this->assertFalse(
            Gate::forUser($userSinRol)->allows('view-biometrics'),
            'Un usuario con rol null no debe tener acceso a view-biometrics.'
        );
    }
}
