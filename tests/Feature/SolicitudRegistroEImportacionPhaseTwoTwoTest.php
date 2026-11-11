<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Models\User;
use App\Services\SolicitudElementosImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SolicitudRegistroEImportacionPhaseTwoTwoTest extends TestCase
{
    use RefreshDatabase;

    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    private function createSpreadsheetFile(array $data, string $extension = 'xlsx'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($data);

        $tempPath = tempnam(sys_get_temp_dir(), 'test_cred_') . '.' . $extension;
        $this->tempFiles[] = $tempPath;

        if ($extension === 'xlsx') {
            $writer = new Xlsx($spreadsheet);
        } else {
            $writer = new Xls($spreadsheet);
        }

        $writer->save($tempPath);

        return $tempPath;
    }

    public function test_01_creacion_correcta_de_una_solicitud(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-SEG-2026-001',
            'fecha_recepcion' => '2026-10-26',
            'area_solicitante' => 'Coordinación Operativa Toluca',
            'observaciones' => 'Solicitud de prueba autorizada',
        ]);

        $this->assertDatabaseHas('solicitudes', [
            'numero_tarjeta' => 'CE/TA25-27/50001/2026',
            'consecutivo_tarjeta' => 50001,
            'numero_oficio' => 'OF-SEG-2026-001',
            'area_solicitante' => 'Coordinación Operativa Toluca',
            'estado_general' => 'recibida',
            'total_elementos' => 0,
            'total_aptos' => 0,
            'total_descartados' => 0,
            'usuario_responsable_id' => $user->id,
        ]);

        $solicitud = SolicitudCredencial::where('numero_oficio', 'OF-SEG-2026-001')->firstOrFail();
        $this->assertSame('2026-10-26', $solicitud->fecha_recepcion->format('Y-m-d'));
        $this->assertSame('CE/TA25-27/50001/2026', $solicitud->numero_tarjeta);
        $this->assertSame(50001, $solicitud->consecutivo_tarjeta);
        $response->assertRedirect(route('credencializacion.detalle', $solicitud->id));
        $response->assertSessionHas('status');
    }

    public function test_02_rechazo_de_tarjetas_y_oficios_duplicados(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Intento de envío manual de número de tarjeta por el cliente (prohibido)
        $responseTarjetaManual = $this->from(route('credencializacion'))->post(route('credencializacion.store'), [
            'numero_tarjeta' => 'MANUAL-FORCED-001',
            'numero_oficio' => 'OF-NUEVO-001',
            'fecha_recepcion' => '2026-10-26',
        ]);
        $responseTarjetaManual->assertSessionHasErrors(['numero_tarjeta']);

        // Registrar una primera solicitud válida autogenerada
        $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-EXISTENTE',
            'fecha_recepcion' => '2026-10-26',
        ]);

        // Mismo número de oficio (debe rechazarse por duplicidad)
        $responseOficio = $this->from(route('credencializacion'))->post(route('credencializacion.store'), [
            'numero_oficio' => 'OF-EXISTENTE',
            'fecha_recepcion' => '2026-10-26',
        ]);
        $responseOficio->assertSessionHasErrors(['numero_oficio']);

        $this->assertSame(1, SolicitudCredencial::count());
    }

    public function test_03_rechazo_de_campos_obligatorios_e_invalidos(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Campos vacíos
        $responseVacio = $this->post(route('credencializacion.store'), []);
        $responseVacio->assertSessionHasErrors(['numero_oficio', 'fecha_recepcion']);

        // Caracteres no permitidos en oficio (ej. símbolos especiales como #, $, %)
        $responseSimbolos = $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OFICIO$100%',
            'fecha_recepcion' => '2026-10-26',
        ]);
        $responseSimbolos->assertSessionHasErrors(['numero_oficio']);

        // Fecha inválida
        $responseFecha = $this->post(route('credencializacion.store'), [
            'numero_oficio' => 'OFICIO-VALIDO-001',
            'fecha_recepcion' => 'fecha-no-valida',
        ]);
        $responseFecha->assertSessionHasErrors(['fecha_recepcion']);
    }

    public function test_04_importacion_correcta_xlsx_y_xls(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $solicitudXlsx = SolicitudCredencial::create([
            'numero_tarjeta' => 'TAR-XLSX-001',
            'numero_oficio' => 'OF-XLSX-001',
        ]);

        $rows = [
            ['CUIP', 'ARMA CORTA', 'ARMA LARGA', 'NOMBRE', 'CATEGORIA'],
            ['TESTCUIP0000000001', 'MC-101', 'ML-201', 'JUAN PEREZ', 'OFICIAL'],
            ['TESTCUIP0000000002', 'MC-102', '', 'MARIA LOPEZ', 'SUBINSPECTOR'],
        ];

        // 1. Probar XLSX
        $filePathXlsx = $this->createSpreadsheetFile($rows, 'xlsx');
        $uploadedXlsx = new UploadedFile($filePathXlsx, 'elementos.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $responseXlsx = $this->post(route('credencializacion.elementos.subir', $solicitudXlsx->id), [
            'archivo' => $uploadedXlsx,
        ]);

        $responseXlsx->assertRedirect(route('credencializacion.detalle', $solicitudXlsx->id));
        $this->assertSame(2, $solicitudXlsx->elementos()->count());
        $solicitudXlsx->refresh();
        $this->assertSame(2, $solicitudXlsx->total_elementos);
        $this->assertSame('recibida', $solicitudXlsx->estado_general);

        // 2. Probar XLS
        $solicitudXls = SolicitudCredencial::create([
            'numero_tarjeta' => 'TAR-XLS-001',
            'numero_oficio' => 'OF-XLS-001',
        ]);

        $filePathXls = $this->createSpreadsheetFile($rows, 'xls');
        $uploadedXls = new UploadedFile($filePathXls, 'elementos.xls', 'application/vnd.ms-excel', null, true);

        $responseXls = $this->post(route('credencializacion.elementos.subir', $solicitudXls->id), [
            'archivo' => $uploadedXls,
        ]);

        $responseXls->assertRedirect(route('credencializacion.detalle', $solicitudXls->id));
        $this->assertSame(2, $solicitudXls->elementos()->count());
        $solicitudXls->refresh();
        $this->assertSame(2, $solicitudXls->total_elementos);
    }

    public function test_05_conservacion_de_filas_con_cuip_vacio(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TAR-CUIP-VACIO-001',
            'numero_oficio' => 'OF-CUIP-VACIO-001',
        ]);

        $rows = [
            ['CUIP', 'ARMA CORTA', 'ARMA LARGA', 'NOMBRE'],
            ['TESTCUIPVAL1111111', 'MC-111', 'ML-111', 'ELEMENTO CON CUIP'],
            ['', 'MC-222', '', 'ELEMENTO SIN CUIP UNO'], // CUIP vacío
            ['   ', 'MC-333', 'ML-333', 'ELEMENTO SIN CUIP ESPACIOS'], // CUIP solo espacios
        ];

        $filePath = $this->createSpreadsheetFile($rows, 'xlsx');
        $uploaded = new UploadedFile($filePath, 'vacio.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->post(route('credencializacion.elementos.subir', $solicitud->id), [
            'archivo' => $uploaded,
        ])->assertRedirect(route('credencializacion.detalle', $solicitud->id));

        $this->assertSame(3, $solicitud->elementos()->count());

        $sinCuip = $solicitud->elementos()->whereNull('cuip')->get();
        $this->assertCount(2, $sinCuip);

        // Verifica que los datos originales conserven el nombre y las armas
        $this->assertSame('MC-222', $sinCuip[0]->matricula_corta);
        $this->assertSame('ELEMENTO SIN CUIP UNO', $sinCuip[0]->datos_solicitados['nombre']);
        $this->assertSame('MC-333', $sinCuip[1]->matricula_corta);
    }

    public function test_06_conservacion_de_cuip_duplicados_sin_fusionar_registros(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TAR-DUP-001',
            'numero_oficio' => 'OF-DUP-001',
        ]);

        $cuipDuplicado = 'DUPLICADO990101HDF00';
        $rows = [
            ['CUIP', 'ARMA CORTA', 'ARMA LARGA', 'NOMBRE'],
            [$cuipDuplicado, 'MC-01', '', 'ELEMENTO DUPLICADO FILA 2'],
            [$cuipDuplicado, '', 'ML-02', 'ELEMENTO DUPLICADO FILA 3'],
        ];

        $filePath = $this->createSpreadsheetFile($rows, 'xlsx');
        $uploaded = new UploadedFile($filePath, 'duplicados.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->post(route('credencializacion.elementos.subir', $solicitud->id), [
            'archivo' => $uploaded,
        ])->assertRedirect(route('credencializacion.detalle', $solicitud->id));

        // Ambos registros deben persistir de manera independiente sin fusionarse ni descartarse
        $duplicadosEnBd = $solicitud->elementos()->where('cuip', $cuipDuplicado)->orderBy('numero_fila')->get();
        $this->assertCount(2, $duplicadosEnBd);
        $this->assertSame(2, $duplicadosEnBd[0]->numero_fila);
        $this->assertSame('MC-01', $duplicadosEnBd[0]->matricula_corta);
        $this->assertSame(3, $duplicadosEnBd[1]->numero_fila);
        $this->assertSame('ML-02', $duplicadosEnBd[1]->matricula_larga);
    }

    public function test_07_conservacion_del_numero_de_fila_original_y_datos_originales(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TAR-FILA-001',
            'numero_oficio' => 'OF-FILA-001',
        ]);

        $rows = [
            ['CUIP', 'ARMA CORTA', 'ARMA LARGA', 'COLUMNA_EXTRA_ARBITRARIA'], // Fila 1 (header)
            ['CUIP0001', 'MC-1', 'ML-1', 'VALOR_A'],                          // Fila 2
            [null, null, null, null],                                          // Fila 3 (vacía intermedia)
            ['CUIP0002', 'MC-2', 'ML-2', 'VALOR_B'],                          // Fila 4
        ];

        $filePath = $this->createSpreadsheetFile($rows, 'xlsx');
        $uploaded = new UploadedFile($filePath, 'filas.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->post(route('credencializacion.elementos.subir', $solicitud->id), [
            'archivo' => $uploaded,
        ]);

        $elementos = $solicitud->elementos()->orderBy('numero_fila')->get();
        $this->assertCount(2, $elementos);

        $this->assertSame(2, $elementos[0]->numero_fila);
        $this->assertSame('VALOR_A', $elementos[0]->datos_originales_fila['COLUMNA_EXTRA_ARBITRARIA']);

        $this->assertSame(4, $elementos[1]->numero_fila);
        $this->assertSame('VALOR_B', $elementos[1]->datos_originales_fila['COLUMNA_EXTRA_ARBITRARIA']);
    }

    public function test_08_mapeo_correcto_de_matriculas_corta_y_larga(): void
    {
        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TAR-MAPEO-001',
            'numero_oficio' => 'OF-MAPEO-001',
        ]);

        // Cabeceras con variaciones comunes
        $rows = [
            ['Clave Única (CUIP)', 'Matrícula Corta (Pistola)', 'Arma Larga (Fusil)'],
            ['CUIPTEST999', 'SHORT-SERIAL-123', 'LONG-SERIAL-456'],
        ];

        $filePath = $this->createSpreadsheetFile($rows, 'xlsx');

        $importer = new SolicitudElementosImporter();
        $result = $importer->import($solicitud, $filePath);

        $this->assertSame(1, $result['total']);
        $elemento = $solicitud->elementos()->firstOrFail();
        $this->assertSame('SHORT-SERIAL-123', $elemento->matricula_corta);
        $this->assertSame('LONG-SERIAL-456', $elemento->matricula_larga);
        $this->assertNull($elemento->resultado_prefiltro);
    }

    public function test_09_rechazo_seguro_de_archivos_ilegibles_o_de_estructura_ambigua(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TAR-ESTRUCTURA-001',
            'numero_oficio' => 'OF-ESTRUCTURA-001',
        ]);

        // 1. Archivo sin columna CUIP
        $rowsSinCuip = [
            ['NOMBRE', 'ARMA CORTA', 'ARMA LARGA'],
            ['PEDRO PEREZ', 'MC-1', 'ML-1'],
        ];
        $fileSinCuip = $this->createSpreadsheetFile($rowsSinCuip, 'xlsx');
        $uploadedSinCuip = new UploadedFile($fileSinCuip, 'sin_cuip.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $responseSinCuip = $this->post(route('credencializacion.elementos.subir', $solicitud->id), [
            'archivo' => $uploadedSinCuip,
        ]);
        $responseSinCuip->assertSessionHasErrors(['archivo']);

        // 2. Archivo con CUIP pero sin ninguna columna de armas
        $rowsSinArmas = [
            ['CUIP', 'NOMBRE', 'MUNICIPIO'],
            ['TESTCUIP0001', 'PEDRO PEREZ', 'TOLUCA'],
        ];
        $fileSinArmas = $this->createSpreadsheetFile($rowsSinArmas, 'xlsx');
        $uploadedSinArmas = new UploadedFile($fileSinArmas, 'sin_armas.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $responseSinArmas = $this->post(route('credencializacion.elementos.subir', $solicitud->id), [
            'archivo' => $uploadedSinArmas,
        ]);
        $responseSinArmas->assertSessionHasErrors(['archivo']);

        // El expediente debe permanecer limpio
        $this->assertSame(0, $solicitud->elementos()->count());
    }

    public function test_10_reversion_de_la_importacion_ante_fallo_de_persistencia(): void
    {
        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TAR-ROLLBACK-001',
            'numero_oficio' => 'OF-ROLLBACK-001',
        ]);

        $rows = [
            ['CUIP', 'ARMA CORTA', 'ARMA LARGA'],
            ['CUIP1', 'MC-1', 'ML-1'],
            ['CUIP2', 'MC-2', 'ML-2'],
            ['CUIP3', 'MC-3', 'ML-3'],
        ];
        $filePath = $this->createSpreadsheetFile($rows, 'xlsx');

        // Simulamos un fallo durante la persistencia del segundo elemento
        SolicitudElemento::saving(function (SolicitudElemento $el): void {
            if ($el->numero_fila === 3) {
                throw new \RuntimeException('Fallo simulado de base de datos durante la persistencia.');
            }
        });

        $importer = new SolicitudElementosImporter();

        try {
            $importer->import($solicitud, $filePath);
            $this->fail('Se esperaba una RuntimeException por fallo simulado.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Fallo simulado', $e->getMessage());
        }

        // Se verifica que la transacción se revirtió por completo
        $this->assertSame(0, $solicitud->elementos()->count());
        $solicitud->refresh();
        $this->assertSame(0, $solicitud->total_elementos);
    }

    public function test_11_prevencion_de_cargas_duplicadas_accidentales(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TAR-SEGUNDA-CARGA-001',
            'numero_oficio' => 'OF-SEGUNDA-CARGA-001',
        ]);

        $rows = [
            ['CUIP', 'ARMA CORTA', 'ARMA LARGA'],
            ['CUIP1', 'MC-1', 'ML-1'],
        ];
        $filePath = $this->createSpreadsheetFile($rows, 'xlsx');
        $uploaded = new UploadedFile($filePath, 'carga1.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        // Primera carga exitosa
        $this->post(route('credencializacion.elementos.subir', $solicitud->id), [
            'archivo' => $uploaded,
        ])->assertRedirect(route('credencializacion.detalle', $solicitud->id));

        $this->assertSame(1, $solicitud->elementos()->count());

        // Segunda carga sobre el mismo expediente debe ser rechazada explícitamente
        $responseSegunda = $this->post(route('credencializacion.elementos.subir', $solicitud->id), [
            'archivo' => $uploaded,
        ]);
        $responseSegunda->assertSessionHasErrors(['archivo']);

        // El conteo se mantiene intacto en 1
        $this->assertSame(1, $solicitud->elementos()->count());
    }

    public function test_12_proteccion_de_rutas_y_validacion_del_servidor(): void
    {
        // Peticiones POST como invitado sin autenticación
        $this->post(route('credencializacion.store'), [
            'numero_tarjeta' => 'AVD/TEST',
            'numero_oficio' => 'OF/TEST',
            'fecha_recepcion' => '2026-10-26',
        ])->assertRedirect(route('login'));

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TAR-INV-001',
            'numero_oficio' => 'OF-INV-001',
        ]);

        $this->post(route('credencializacion.elementos.subir', $solicitud->id), [])
            ->assertRedirect(route('login'));
    }

    public function test_13_interfaz_muestra_elementos_cargados_y_filas(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TAR-UI-001',
            'numero_oficio' => 'OF-UI-001',
            'fecha_recepcion' => '2026-10-26',
            'total_elementos' => 2,
        ]);

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPUI0001',
            'matricula_corta' => 'COR-UI-01',
            'matricula_larga' => 'LAR-UI-01',
            'datos_solicitados' => ['nombre' => 'ELEMENTO UI 1', 'municipio' => 'TOLUCA'],
            'datos_originales_fila' => ['CUIP' => 'CUIPUI0001', 'NOMBRE' => 'ELEMENTO UI 1'],
        ]);

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 3,
            'cuip' => null,
            'matricula_corta' => 'COR-UI-02',
            'datos_solicitados' => ['nombre' => 'ELEMENTO SIN CUIP', 'municipio' => 'METEPEC'],
            'datos_originales_fila' => ['CUIP' => '', 'NOMBRE' => 'ELEMENTO SIN CUIP'],
        ]);

        $response = $this->get(route('credencializacion.detalle', $solicitud->id));
        $response->assertOk()
            ->assertSee('TAR-UI-001')
            ->assertSee('OF-UI-001')
            ->assertSee('#2')
            ->assertSee('CUIPUI0001')
            ->assertSee('COR-UI-01')
            ->assertSee('LAR-UI-01')
            ->assertSee('ELEMENTO UI 1')
            ->assertSee('#3')
            ->assertSee('Sin CUIP (conservado)')
            ->assertSee('COR-UI-02')
            ->assertSee('ELEMENTO SIN CUIP')
            ->assertSee('Pendiente Fase 2.3');
    }

    public function test_14_importacion_con_cabeceras_desplazadas_y_filas_preambulo_como_ejemplo_de_solicitud(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TAR-ROW18-001',
            'numero_oficio' => 'OF-ROW18-001',
        ]);

        $rows = [];
        // 15 filas vacías
        for ($i = 0; $i < 15; $i++) {
            $rows[] = [null, null, null, null, null];
        }
        // Fila 16: Título general
        $rows[] = ['FORMATO DE REMISIÓN DE INFORMACIÓN PARA ELABORACIÓN DE CREDENCIALES', null, null, null, null];
        // Fila 17: Vacía
        $rows[] = [null, null, null, null, null];
        // Fila 18: Cabeceras reales
        $rows[] = [
            'No. PROGRESIVO',
            'CUIP (datos registrados en relino).',
            'NOMBRE (A_Paterno,A_Materno)',
            'MATRICULA DEL ARMA CORTA',
            'MATRICULA DEL ARMA LARGA',
            'MUNICIPIO',
        ];
        // Fila 19: Elemento con arma larga
        $rows[] = ['1', 'AALE930506H154940060', 'ALCANTARA LOPEZ EDGAR', '', '45100635', 'ATLACOMULCO'];
        // Fila 20: Elemento con arma corta
        $rows[] = ['2', 'AARA991115H155364669', 'ANASTACIO REYES ADRIAN', '45304904', '', 'ATLACOMULCO'];

        $filePath = $this->createSpreadsheetFile($rows, 'xlsx');
        $uploaded = new UploadedFile($filePath, 'solicitud_desplazada.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->post(route('credencializacion.elementos.subir', $solicitud->id), [
            'archivo' => $uploaded,
        ]);

        $response->assertRedirect(route('credencializacion.detalle', $solicitud->id));
        $this->assertSame(2, $solicitud->elementos()->count());

        $el1 = $solicitud->elementos()->where('numero_fila', 19)->firstOrFail();
        $this->assertSame('AALE930506H154940060', $el1->cuip);
        $this->assertNull($el1->matricula_corta);
        $this->assertSame('45100635', $el1->matricula_larga);
        $this->assertSame('ALCANTARA LOPEZ EDGAR', $el1->datos_solicitados['nombre']);

        $el2 = $solicitud->elementos()->where('numero_fila', 20)->firstOrFail();
        $this->assertSame('AARA991115H155364669', $el2->cuip);
        $this->assertSame('45304904', $el2->matricula_corta);
        $this->assertNull($el2->matricula_larga);
    }

    public function test_15_titulo_superior_con_palabra_cuip_no_bloquea_cabecera_real_inferior(): void
    {
        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TAR-TITLE-001',
            'numero_oficio' => 'OF-TITLE-001',
        ]);

        $rows = [
            ['RELACIÓN DE PERSONAL CON CUIP PARA TRÁMITE', null, null, null], // Fila 1: Título con palabra "CUIP" pero sin armas
            [null, null, null, null],                                          // Fila 2: Vacía
            ['CUIP', 'MATRÍCULA ARMA CORTA', 'MATRÍCULA ARMA LARGA', 'NOMBRE'], // Fila 3: Cabeceras reales
            ['TESTCUIP0000000001', 'MC-101', 'ML-201', 'JUAN PEREZ'],          // Fila 4: Datos
        ];

        $filePath = $this->createSpreadsheetFile($rows, 'xlsx');
        $importer = new SolicitudElementosImporter();
        $result = $importer->import($solicitud, $filePath);

        $this->assertSame(1, $result['total']);
        $elemento = $solicitud->elementos()->firstOrFail();
        $this->assertSame('TESTCUIP0000000001', $elemento->cuip);
        $this->assertSame('MC-101', $elemento->matricula_corta);
        $this->assertSame('ML-201', $elemento->matricula_larga);
    }

    public function test_16_importacion_directa_archivo_real_ejemplo_de_solicitud_si_existe(): void
    {
        $realFile = base_path('Gestion de informacion/Ejemplo de solicitud.xlsx');
        if (!file_exists($realFile)) {
            $this->markTestSkipped('El archivo Gestion de informacion/Ejemplo de solicitud.xlsx no existe en esta ruta.');
        }

        $solicitud = SolicitudCredencial::create([
            'numero_tarjeta' => 'TAR-REAL-001',
            'numero_oficio' => 'OF-REAL-001',
        ]);

        $importer = new SolicitudElementosImporter();
        $result = $importer->import($solicitud, $realFile);

        $this->assertSame(26, $result['total']);
        $this->assertSame(26, $solicitud->elementos()->count());

        // Verificar el primer elemento (fila 19 física en Excel)
        $primero = $solicitud->elementos()->where('numero_fila', 19)->firstOrFail();
        $this->assertSame('AALE930506H154940060', $primero->cuip);
        $this->assertSame('ALCANTARA LOPEZ EDGAR', $primero->datos_solicitados['nombre']);
        $this->assertNull($primero->matricula_corta);
        $this->assertSame('45100635', $primero->matricula_larga);

        // Verificar el último elemento (fila 44 física en Excel)
        $ultimo = $solicitud->elementos()->where('numero_fila', 44)->firstOrFail();
        $this->assertSame('VIPL930502H155364246', $ultimo->cuip);
        $this->assertSame('VILCHIS PACHECO JOSE LUIS', $ultimo->datos_solicitados['nombre']);
        $this->assertSame('UU679823', $ultimo->matricula_corta);
        $this->assertSame('45100748', $ultimo->matricula_larga);
    }
}
