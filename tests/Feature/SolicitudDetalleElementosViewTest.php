<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LocArma;
use App\Models\LocCatalogoOrganizacional;
use App\Models\LocCatalogoVersion;
use App\Models\LocImportBatch;
use App\Models\LocPersona;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Models\User;
use App\Support\AgrupamientoInstitucional;
use App\Support\MotivoDescarte;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SolicitudDetalleElementosViewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private LocImportBatch $batch;
    private LocCatalogoVersion $catalogoVersion;

    protected function setUp(): void
    {
        parent::setUp();

        AgrupamientoInstitucional::limpiarCache();

        $this->user = User::factory()->create();

        $this->batch = LocImportBatch::create([
            'tipo_fuente' => 'catalogo_organizacional_loc',
            'nombre_archivo' => 'Catalogo_2026.xlsx',
            'estado' => 'completado',
            'fecha_confirmacion' => Carbon::now()->subDays(1),
            'usuario_id' => $this->user->id,
            'total_leidas' => 10,
            'total_insertadas' => 10,
        ]);

        $this->catalogoVersion = LocCatalogoVersion::create([
            'import_batch_id' => $this->batch->id,
            'version_numero' => 1,
            'es_vigente' => true,
            'total_registros' => 50,
            'archivo_nombre' => 'Catalogo_Oficial_2026.xlsx',
            'usuario_id' => $this->user->id,
            'fecha_activacion' => Carbon::now()->subDays(1),
        ]);
    }

    private function createSolicitud(int $totalElementos = 1): SolicitudCredencial
    {
        return SolicitudCredencial::create([
            'numero_tarjeta' => 'CE/TA25-27/50001/2026',
            'consecutivo_tarjeta' => 50001,
            'numero_oficio' => 'OF-VIEW-' . uniqid(),
            'fecha_recepcion' => now()->toDateString(),
            'estado_general' => 'recibida',
            'usuario_responsable_id' => $this->user->id,
            'total_elementos' => $totalElementos,
            'total_aptos' => 0,
            'total_descartados' => 0,
        ]);
    }

    /** 1. Renderizado de los nueve conceptos en la cabecera de la tabla */
    public function test_01_renderizado_de_los_nueve_conceptos_de_la_tabla(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(1);

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIPTEST1111111111',
            'tipo_elemento' => 'persona',
            'estado_tramite' => 'recibido',
        ]);

        $response = $this->get(route('credencializacion.detalle', $solicitud->id));
        $response->assertOk();

        // 9 cabeceras esperadas
        $response->assertSee('# / Fila');
        $response->assertSee('CUIP');
        $response->assertSee('Persona');
        $response->assertSee('Agrupamiento');
        $response->assertSee('Arma Corta');
        $response->assertSee('Arma Larga');
        $response->assertSee('Prefiltro');
        $response->assertSee('Motivos');
        $response->assertSee('Estado trámite');
    }

    /** 2. Numeración visual desde 1 */
    public function test_02_numeracion_visual_desde_1(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(2);

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 19,
            'cuip' => 'CUIPTEST1111111111',
            'tipo_elemento' => 'persona',
            'datos_solicitados' => ['nombre' => 'JUAN PEREZ'],
            'estado_tramite' => 'recibido',
        ]);

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 20,
            'cuip' => 'CUIPTEST2222222222',
            'tipo_elemento' => 'persona',
            'datos_solicitados' => ['nombre' => 'MARIA LOPEZ'],
            'estado_tramite' => 'recibido',
        ]);

        $response = $this->get(route('credencializacion.detalle', $solicitud->id));
        $response->assertOk();

        // Debe renderizar #1 y #2 como números principales
        $response->assertSee('#1');
        $response->assertSee('#2');
    }

    /** 3. Numeración correcta en páginas posteriores tomando en cuenta el offset */
    public function test_03_numeracion_correcta_en_paginas_posteriores(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(52);

        $insertData = [];
        for ($i = 1; $i <= 52; $i++) {
            $insertData[] = [
                'solicitud_id' => $solicitud->id,
                'numero_fila' => 100 + $i,
                'cuip' => sprintf('CUIPPAG%011d', $i),
                'tipo_elemento' => 'persona',
                'datos_solicitados' => json_encode(['nombre' => "PERSONA {$i}"]),
                'estado_tramite' => 'recibido',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        SolicitudElemento::insert($insertData);

        // Página 2 (elementos 51 y 52)
        $response = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'page' => 2]));
        $response->assertOk();

        $response->assertSee('#51');
        $response->assertSee('#52');
        $response->assertSee('PERSONA 51');
        $response->assertSee('PERSONA 52');
    }

    /** 4. Conservación de numero_fila como referencia secundaria de Excel */
    public function test_04_conservacion_de_numero_fila_como_referencia_excel(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud();

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 345,
            'cuip' => 'CUIPEXCELREF123456',
            'tipo_elemento' => 'persona',
            'datos_solicitados' => ['nombre' => 'ROBERTO CARLOS'],
            'estado_tramite' => 'recibido',
        ]);

        $response = $this->get(route('credencializacion.detalle', $solicitud->id));
        $response->assertOk();

        $response->assertSee('#1');
        $response->assertSee('Fila Excel: 345');
    }

    /** 5. Agrupamiento con clave verificada en catálogo */
    public function test_05_agrupamiento_con_clave_verificada(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud();

        LocCatalogoOrganizacional::create([
            'import_batch_id' => $this->batch->id,
            'clave_origen' => 'A302',
            'nombre' => 'COORDINACION VALLE TOLUCA PARA LA PREVENCION DE ROBO AL TRANSPORTE',
            'agrupamiento' => 'COORDINACION VALLE TOLUCA PARA LA PREVENCION DE ROBO AL TRANSPORTE',
            'activo' => true,
        ]);

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIPAGRVER12345678',
            'tipo_elemento' => 'persona',
            'datos_solicitados' => [
                'nombre' => 'ELEMENTO VERIFICADO',
                'adscripcion' => 'COORDINACION VALLE TOLUCA PARA LA PREVENCION DE ROBO AL TRANSPORTE',
            ],
            'estado_tramite' => 'recibido',
        ]);

        $response = $this->get(route('credencializacion.detalle', $solicitud->id));
        $response->assertOk();

        $response->assertSee('[A302]');
        $response->assertSee('COORDINACION VALLE TOLUCA PARA LA PREVENCION DE ROBO AL TRANSPORTE');
    }

    /** 6. Agrupamiento con texto origen sin clave */
    public function test_06_agrupamiento_con_texto_origen_sin_clave(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud();

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIPSINCLAVE123456',
            'tipo_elemento' => 'persona',
            'datos_solicitados' => [
                'nombre' => 'ELEMENTO SIN CLAVE',
                'adscripcion' => 'DESTACAMENTO ESPECIAL NO REGISTRADO',
            ],
            'estado_tramite' => 'recibido',
        ]);

        $response = $this->get(route('credencializacion.detalle', $solicitud->id));
        $response->assertOk();

        $response->assertSee('DESTACAMENTO ESPECIAL NO REGISTRADO');
        $response->assertSee('Sin clave institucional verificada');
    }

    /** 7. Agrupamiento ambiguo o discrepante */
    public function test_07_agrupamiento_ambiguo_o_discrepante(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(2);

        // 7A: Discrepante entre Excel y plantilla de personal
        $persona = LocPersona::create([
            'import_batch_id' => $this->batch->id,
            'cuip' => 'CUIPDISCREP1234567',
            'nombre' => 'OFICIAL DISCREPANTE',
            'agrupamiento' => 'DIRECCION DE TRANSITO OFICIAL',
            'activo' => true,
        ]);

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIPDISCREP1234567',
            'persona_id' => $persona->id,
            'tipo_elemento' => 'persona',
            'datos_solicitados' => [
                'nombre' => 'OFICIAL DISCREPANTE',
                'adscripcion' => 'COORDINACION REGIONAL SOLICITADA',
            ],
            'estado_tramite' => 'recibido',
        ]);

        // 7B: Ambiguo por homónimos en catálogo con claves distintas
        LocCatalogoOrganizacional::create([
            'import_batch_id' => $this->batch->id,
            'clave_origen' => 'C100',
            'nombre' => 'MODULO METROPOLITANO',
            'agrupamiento' => 'MODULO METROPOLITANO',
            'activo' => true,
        ]);
        LocCatalogoOrganizacional::create([
            'import_batch_id' => $this->batch->id,
            'clave_origen' => 'C200',
            'nombre' => 'MODULO METROPOLITANO',
            'agrupamiento' => 'MODULO METROPOLITANO',
            'activo' => true,
        ]);

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPAMBIGUO1234567',
            'tipo_elemento' => 'persona',
            'datos_solicitados' => [
                'nombre' => 'ELEMENTO AMBIGUO',
                'adscripcion' => 'MODULO METROPOLITANO',
            ],
            'estado_tramite' => 'recibido',
        ]);

        $response = $this->get(route('credencializacion.detalle', $solicitud->id));
        $response->assertOk();

        // Verifica caso discrepante
        $response->assertSee('Discrepante');
        $response->assertSee('COORDINACION REGIONAL SOLICITADA');
        $response->assertSee('DIRECCION DE TRANSITO OFICIAL');

        // Verifica caso ambiguo
        $response->assertSee('Ambiguo');
        $response->assertSee('MODULO METROPOLITANO');
    }

    /** 8. Agrupamiento sin información */
    public function test_08_agrupamiento_sin_informacion(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud();

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIPSINAGR12345678',
            'tipo_elemento' => 'persona',
            'datos_solicitados' => [
                'nombre' => 'ELEMENTO SIN AGRUPAMIENTO',
            ],
            'estado_tramite' => 'recibido',
        ]);

        $response = $this->get(route('credencializacion.detalle', $solicitud->id));
        $response->assertOk();

        $response->assertSee('—');
    }

    /** 9. Arma vinculada con metadatos oficiales */
    public function test_09_arma_vinculada_con_metadatos_oficiales(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud();

        $armaCorta = LocArma::create([
            'import_batch_id' => $this->batch->id,
            'matricula' => 'BER12345Z',
            'tipo_arma' => 'CORTA',
            'clase_arma' => 'PISTOLA',
            'marca' => 'BERETTA',
            'modelo' => '92 FS',
            'calibre' => '9 MM.',
            'estatus' => 'FISICA',
            'activo' => true,
        ]);

        $armaLarga = LocArma::create([
            'import_batch_id' => $this->batch->id,
            'matricula' => 'COLT789X',
            'tipo_arma' => 'LARGA',
            'clase_arma' => 'FUSIL',
            'marca' => 'COLT',
            'modelo' => 'M4 COMMANDO',
            'calibre' => '5.56 MM.',
            'estatus' => 'FISICA',
            'activo' => true,
        ]);

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIPARMAS123456789',
            'matricula_corta' => 'BER12345Z',
            'matricula_larga' => 'COLT789X',
            'arma_corta_id' => $armaCorta->id,
            'arma_larga_id' => $armaLarga->id,
            'tipo_elemento' => 'persona',
            'estado_tramite' => 'recibido',
        ]);

        $response = $this->get(route('credencializacion.detalle', $solicitud->id));
        $response->assertOk();

        // Arma corta metadatos
        $response->assertSee('BER12345Z');
        $response->assertSee('BERETTA');
        $response->assertSee('92 FS');
        $response->assertSee('9 MM.');

        // Arma larga metadatos
        $response->assertSee('COLT789X');
        $response->assertSee('COLT');
        $response->assertSee('M4 COMMANDO');
        $response->assertSee('5.56 MM.');
    }

    /** 10. Matrícula solicitada sin arma vinculada */
    public function test_10_matricula_solicitada_sin_arma_vinculada(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud();

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIPARMANO12345678',
            'matricula_corta' => 'MATRICULA-FANTASMA',
            'arma_corta_id' => null,
            'tipo_elemento' => 'persona',
            'estado_tramite' => 'recibido',
        ]);

        $response = $this->get(route('credencializacion.detalle', $solicitud->id));
        $response->assertOk();

        $response->assertSee('MATRICULA-FANTASMA');
        $response->assertSee('No vinculada');
    }

    /** 11. Motivo único */
    public function test_11_motivo_unico(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud();

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => '',
            'tipo_elemento' => 'persona',
            'resultado_prefiltro' => 'Descartado',
            'codigos_descarte' => [MotivoDescarte::CUIP_VACIO],
            'motivos_descarte' => ['El elemento no cuenta con una clave CUIP válida'],
            'estado_tramite' => 'descartado',
        ]);

        $response = $this->get(route('credencializacion.detalle', $solicitud->id));
        $response->assertOk();

        // Debe renderizar la etiqueta institucional directamente
        $response->assertSee('CUIP no proporcionado');
        $response->assertDontSee('+1 más');
    }

    /** 12. Múltiples motivos con indicador +N más */
    public function test_12_multiples_motivos_con_indicador_mas_n(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud();

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIPMULT1234567890',
            'tipo_elemento' => 'persona',
            'resultado_prefiltro' => 'Descartado',
            'codigos_descarte' => [
                MotivoDescarte::CUIP_NO_LOCALIZADO,
                MotivoDescarte::ARM_SIN_ASIGNACION,
                MotivoDescarte::EXP_CANCELACION,
            ],
            'motivos_descarte' => [
                'CUIP no localizado',
                'Sin asignación de armamento',
                'Credencial cancelada',
            ],
            'estado_tramite' => 'descartado',
        ]);

        $response = $this->get(route('credencializacion.detalle', $solicitud->id));
        $response->assertOk();

        // Primer motivo visible
        $response->assertSee('Persona no localizada');
        // Indicador +2 más
        $response->assertSee('+2 más');
    }

    /** 13. Motivo histórico sin códigos estructurados */
    public function test_13_motivo_historico_sin_codigos_estructurados(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud();

        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIPHIST1234567890',
            'tipo_elemento' => 'persona',
            'resultado_prefiltro' => 'Descartado',
            'codigos_descarte' => null, // Histórico sin clasificación de Fase 2C
            'motivos_descarte' => ['El elemento presenta dictamen negativo emitido en 2024'],
            'estado_tramite' => 'descartado',
        ]);

        $response = $this->get(route('credencializacion.detalle', $solicitud->id));
        $response->assertOk();

        $response->assertSee('Motivo histórico');
        $response->assertSee('El elemento presenta dictamen negativo emitido en 2024');
    }

    /** 14. Separación semántica entre prefiltro y estado del trámite */
    public function test_14_separacion_entre_prefiltro_y_estado_del_tramite(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(2);

        // Elemento descartado
        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIPSEP12345678901',
            'tipo_elemento' => 'persona',
            'resultado_prefiltro' => 'Descartado',
            'codigos_descarte' => [MotivoDescarte::ARM_SIN_ASIGNACION],
            'estado_tramite' => 'descartado',
        ]);

        // Elemento apto
        SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPSEP12345678902',
            'tipo_elemento' => 'persona',
            'resultado_prefiltro' => 'Apto',
            'estado_tramite' => 'apto',
        ]);

        $response = $this->get(route('credencializacion.detalle', $solicitud->id));
        $response->assertOk();

        // En prefiltro:
        $response->assertSee('Descartado');
        $response->assertSee('Apto');

        // En estado del trámite no duplica el badge rojo de descarte:
        $response->assertSee('Trámite detenido');
        $response->assertSee('Apto para trámite');
    }

    /** 15. Ausencia de problema N+1 en relaciones de persona y armamento */
    public function test_15_ausencia_de_n_plus_one_en_relaciones_de_persona_y_armamento(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(15);

        // Crear 15 personas y armas
        for ($i = 1; $i <= 15; $i++) {
            $p = LocPersona::create([
                'import_batch_id' => $this->batch->id,
                'cuip' => sprintf('CUIPN1P%011d', $i),
                'nombre' => "OFICIAL N1 #{$i}",
                'agrupamiento' => 'COORDINACION REGIONAL',
                'activo' => true,
            ]);

            $ac = LocArma::create([
                'import_batch_id' => $this->batch->id,
                'matricula' => "MAT-C-{$i}",
                'tipo_arma' => 'CORTA',
                'clase_arma' => 'PISTOLA',
                'marca' => 'GLOCK',
                'modelo' => '17',
                'calibre' => '9 MM.',
                'activo' => true,
            ]);

            $al = LocArma::create([
                'import_batch_id' => $this->batch->id,
                'matricula' => "MAT-L-{$i}",
                'tipo_arma' => 'LARGA',
                'clase_arma' => 'FUSIL',
                'marca' => 'COLT',
                'modelo' => 'M4',
                'calibre' => '5.56 MM.',
                'activo' => true,
            ]);

            SolicitudElemento::create([
                'solicitud_id' => $solicitud->id,
                'numero_fila' => $i,
                'cuip' => sprintf('CUIPN1P%011d', $i),
                'persona_id' => $p->id,
                'arma_corta_id' => $ac->id,
                'arma_larga_id' => $al->id,
                'matricula_corta' => "MAT-C-{$i}",
                'matricula_larga' => "MAT-L-{$i}",
                'tipo_elemento' => 'persona',
                'resultado_prefiltro' => 'Apto',
                'estado_tramite' => 'apto',
            ]);
        }

        $queryCount = 0;
        DB::listen(function ($query) use (&$queryCount) {
            $queryCount++;
        });

        $response = $this->get(route('credencializacion.detalle', $solicitud->id));
        $response->assertOk();

        // Si hubiera N+1, para 15 elementos se ejecutarían:
        // 1 count + 1 elementos + 15 personas + 15 armas cortas + 15 armas largas = 47 queries adicionales
        // Con eager loading, solo se ejecutan queries fijas (máximo 12 queries para todo el ciclo HTTP de Laravel).
        $this->assertLessThanOrEqual(
            12,
            $queryCount,
            "Se detectó posible problema N+1: se ejecutaron {$queryCount} consultas para 15 elementos."
        );
    }
}
