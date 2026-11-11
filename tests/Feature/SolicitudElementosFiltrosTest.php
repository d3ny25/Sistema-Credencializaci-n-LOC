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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SolicitudElementosFiltrosTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private LocImportBatch $batch;
    private LocCatalogoVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        AgrupamientoInstitucional::limpiarCache();

        $this->user = User::factory()->create([
            'role' => 'superadmin',
        ]);

        $this->batch = LocImportBatch::create([
            'tipo_fuente' => 'catalogo_organizacional_loc',
            'nombre_archivo' => 'catalogo_test.xlsx',
            'estado' => 'completado',
            'usuario_id' => $this->user->id,
            'fecha_confirmacion' => now(),
            'total_leidas' => 10,
            'total_insertadas' => 10,
        ]);

        $this->version = LocCatalogoVersion::create([
            'import_batch_id' => $this->batch->id,
            'version_numero' => 1,
            'archivo_nombre' => 'catalogo_test.xlsx',
            'total_registros' => 10,
            'es_vigente' => true,
            'usuario_id' => $this->user->id,
            'fecha_activacion' => now(),
        ]);
    }

    private function createSolicitud(array $attributes = []): SolicitudCredencial
    {
        static $seq = 60000;
        $seq++;

        return SolicitudCredencial::create(array_merge([
            'numero_tarjeta' => "LOC-2026-{$seq}",
            'consecutivo_tarjeta' => $seq,
            'numero_oficio' => "OF-FILTRO-{$seq}",
            'fecha_recepcion' => now()->toDateString(),
            'estado_general' => 'recibida',
            'total_elementos' => 0,
            'total_aptos' => 0,
            'total_descartados' => 0,
            'usuario_responsable_id' => $this->user->id,
        ], $attributes));
    }

    /** 01. Filtro A: Resultado del prefiltro (Aptos, Descartados, Sin evaluar) */
    public function test_01_filtro_prefiltro_individual_aptos_descartados_y_sin_evaluar(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 3, 'total_aptos' => 1, 'total_descartados' => 1]);

        $eApto = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIPAPTO0000000001',
            'resultado_prefiltro' => 'Apto',
        ]);

        $eDesc = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPDESC0000000001',
            'resultado_prefiltro' => 'Descartado',
            'codigos_descarte' => [MotivoDescarte::ARM_SIN_ASIGNACION],
        ]);

        $eSinEval = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 3,
            'cuip' => 'CUIPSINEVAL0000001',
            'resultado_prefiltro' => null,
        ]);

        // Sin filtro -> todos (3)
        $resp = $this->get(route('credencializacion.detalle', $solicitud->id));
        $resp->assertOk();
        $this->assertCount(3, $resp->viewData('elementos'));

        // Filtro Aptos -> 1
        $respAptos = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'prefiltro' => 'aptos']));
        $respAptos->assertOk();
        $itemsAptos = $respAptos->viewData('elementos');
        $this->assertCount(1, $itemsAptos);
        $this->assertSame($eApto->id, $itemsAptos->first()->id);

        // Filtro Descartados -> 1
        $respDesc = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'prefiltro' => 'descartados']));
        $respDesc->assertOk();
        $itemsDesc = $respDesc->viewData('elementos');
        $this->assertCount(1, $itemsDesc);
        $this->assertSame($eDesc->id, $itemsDesc->first()->id);

        // Filtro Sin evaluar -> 1
        $respSinEval = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'prefiltro' => 'sin_evaluar']));
        $respSinEval->assertOk();
        $itemsSinEval = $respSinEval->viewData('elementos');
        $this->assertCount(1, $itemsSinEval);
        $this->assertSame($eSinEval->id, $itemsSinEval->first()->id);
    }

    /** 02. Filtro B: Motivo de descarte individual y concurrencia de motivos múltiples */
    public function test_02_filtro_motivo_individual_y_coexistencia_de_motivos_multiples(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 3]);

        // e1 tiene 2 motivos: CUIP_VACIO y ARM_SIN_ASIGNACION
        $e1 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => null,
            'resultado_prefiltro' => 'Descartado',
            'codigos_descarte' => [MotivoDescarte::CUIP_VACIO, MotivoDescarte::ARM_SIN_ASIGNACION],
        ]);

        // e2 tiene 1 motivo: ARM_SIN_ASIGNACION
        $e2 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIP00000000000002',
            'resultado_prefiltro' => 'Descartado',
            'codigos_descarte' => [MotivoDescarte::ARM_SIN_ASIGNACION],
        ]);

        // e3 tiene 1 motivo: EXP_CANCELACION
        $e3 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 3,
            'cuip' => 'CUIP00000000000003',
            'resultado_prefiltro' => 'Descartado',
            'codigos_descarte' => [MotivoDescarte::EXP_CANCELACION],
        ]);

        // Filtrar por CUIP_VACIO -> solo e1
        $respCuip = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'motivo' => MotivoDescarte::CUIP_VACIO]));
        $respCuip->assertOk();
        $itemsCuip = $respCuip->viewData('elementos');
        $this->assertCount(1, $itemsCuip);
        $this->assertSame($e1->id, $itemsCuip->first()->id);

        // Filtrar por ARM_SIN_ASIGNACION -> encuentra tanto e1 (múltiple) como e2 (único)
        $respArm = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'motivo' => MotivoDescarte::ARM_SIN_ASIGNACION]));
        $respArm->assertOk();
        $itemsArm = $respArm->viewData('elementos');
        $this->assertCount(2, $itemsArm);
        $this->assertTrue($itemsArm->pluck('id')->contains($e1->id));
        $this->assertTrue($itemsArm->pluck('id')->contains($e2->id));

        // Filtrar por EXP_CANCELACION -> solo e3
        $respCanc = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'motivo' => MotivoDescarte::EXP_CANCELACION]));
        $respCanc->assertOk();
        $itemsCanc = $respCanc->viewData('elementos');
        $this->assertCount(1, $itemsCanc);
        $this->assertSame($e3->id, $itemsCanc->first()->id);
    }

    /** 03. Filtro B: Opción 'historico' incluye descartados con codigos NULL o MOT_SIN_CLASIFICAR y nunca aptos */
    public function test_03_filtro_motivo_historico_incluye_descartados_con_codigos_null_o_mot_sin_clasificar_y_excluye_aptos(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 4]);

        // Descartado con codigos_descarte NULL (histórico pre-Fase 2C)
        $eHistNull = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIPHISTNULL000001',
            'resultado_prefiltro' => 'Descartado',
            'codigos_descarte' => null,
            'motivos_descarte' => ['Causal administrativa no clasificada'],
        ]);

        // Descartado con código técnico MOT_SIN_CLASIFICAR
        $eHistTecnico = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPHISTTEC0000001',
            'resultado_prefiltro' => 'Descartado',
            'codigos_descarte' => [MotivoDescarte::MOT_SIN_CLASIFICAR],
        ]);

        // Descartado moderno con código sustantivo
        $eModerno = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 3,
            'cuip' => 'CUIPMODERNO0000001',
            'resultado_prefiltro' => 'Descartado',
            'codigos_descarte' => [MotivoDescarte::CUIP_DUPLICADO],
        ]);

        // Apto con codigos_descarte NULL (NUNCA debe ser incluido en histórico)
        $eAptoNull = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 4,
            'cuip' => 'CUIPAPTONULL000001',
            'resultado_prefiltro' => 'Apto',
            'codigos_descarte' => null,
        ]);

        $respHist = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'motivo' => 'historico']));
        $respHist->assertOk();
        $itemsHist = $respHist->viewData('elementos');

        $this->assertCount(2, $itemsHist);
        $this->assertTrue($itemsHist->pluck('id')->contains($eHistNull->id));
        $this->assertTrue($itemsHist->pluck('id')->contains($eHistTecnico->id));
        $this->assertFalse($itemsHist->pluck('id')->contains($eModerno->id));
        $this->assertFalse($itemsHist->pluck('id')->contains($eAptoNull->id));
    }

    /** 04. Filtros rechazan parámetros o códigos inválidos con errores de validación */
    public function test_04_filtro_rechaza_codigos_o_parametros_invalidos(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud();

        // Motivo inexistente
        $respInvalido = $this->get(route('credencializacion.detalle', [
            'solicitud' => $solicitud->id,
            'motivo' => 'MOTIVO_MALICIOSO_O_INVENTADO',
        ]));
        $respInvalido->assertSessionHasErrors(['motivo']);

        // Prefiltro inválido
        $respPreInvalido = $this->get(route('credencializacion.detalle', [
            'solicitud' => $solicitud->id,
            'prefiltro' => 'estado_falso',
        ]));
        $respPreInvalido->assertSessionHasErrors(['prefiltro']);

        // Agrupamiento inválido
        $respAgrInvalido = $this->get(route('credencializacion.detalle', [
            'solicitud' => $solicitud->id,
            'agrupamiento' => 'agrupamiento_falso',
        ]));
        $respAgrInvalido->assertSessionHasErrors(['agrupamiento']);

        // Búsqueda q excesiva (> 100 caracteres)
        $respQInvalido = $this->get(route('credencializacion.detalle', [
            'solicitud' => $solicitud->id,
            'q' => str_repeat('A', 101),
        ]));
        $respQInvalido->assertSessionHasErrors(['q']);
    }

    /** 05. Filtro C: Agrupamiento con clave verificada y filtro por clave institucional específica */
    public function test_05_filtro_agrupamiento_verificado_y_filtro_por_clave_especifica(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 3]);

        LocCatalogoOrganizacional::create([
            'clave_origen' => 'A302',
            'nombre' => 'DIRECCION DE SEGURIDAD PUBLICA',
            'agrupamiento' => 'SEGURIDAD PUBLICA',
            'activo' => true,
        ]);

        LocCatalogoOrganizacional::create([
            'clave_origen' => 'B101',
            'nombre' => 'POLICIA DE TRANSITO',
            'agrupamiento' => 'TRANSITO',
            'activo' => true,
        ]);

        $eA302 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIPVERIF000000001',
            'datos_solicitados' => ['adscripcion' => 'DIRECCION DE SEGURIDAD PUBLICA'],
        ]);

        $eB101 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPVERIF000000002',
            'datos_solicitados' => ['adscripcion' => 'POLICIA DE TRANSITO'],
        ]);

        $eSinClave = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 3,
            'cuip' => 'CUIPSINCL000000001',
            'datos_solicitados' => ['adscripcion' => 'AREA INVENTADA NO REGISTRADA'],
        ]);

        // Filtro por estado 'verificado' -> devuelve eA302 y eB101
        $respVerif = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'agrupamiento' => 'verificado']));
        $respVerif->assertOk();
        $itemsVerif = $respVerif->viewData('elementos');
        $this->assertCount(2, $itemsVerif);
        $this->assertTrue($itemsVerif->pluck('id')->contains($eA302->id));
        $this->assertTrue($itemsVerif->pluck('id')->contains($eB101->id));
        $this->assertFalse($itemsVerif->pluck('id')->contains($eSinClave->id));

        // Filtro por clave específica 'A302' -> solo eA302
        $respClave = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'clave' => 'A302']));
        $respClave->assertOk();
        $itemsClave = $respClave->viewData('elementos');
        $this->assertCount(1, $itemsClave);
        $this->assertSame($eA302->id, $itemsClave->first()->id);
    }

    /** 06. Filtro C: Agrupamiento con texto origen sin clave */
    public function test_06_filtro_agrupamiento_sin_clave(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 2]);

        $eSinClave = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIPSINCL000000002',
            'datos_solicitados' => ['adscripcion' => 'MODULO AUXILIAR KM 45'],
        ]);

        $eSinInfo = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPSININFO0000001',
            'datos_solicitados' => [],
        ]);

        $resp = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'agrupamiento' => 'sin_clave']));
        $resp->assertOk();
        $items = $resp->viewData('elementos');
        $this->assertCount(1, $items);
        $this->assertSame($eSinClave->id, $items->first()->id);
    }

    /** 07. Filtro C: Agrupamiento discrepante (Excel vs Plantilla oficial) */
    public function test_07_filtro_agrupamiento_discrepante(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 2]);

        $persona = LocPersona::create([
            'nombre' => 'RICARDO FLORES',
            'cuip' => 'CUIPDISCREP0000001',
            'agrupamiento' => 'REGION IX METEPEC',
        ]);

        // Elemento con discrepancia: Excel solicita 'REGION I TOLUCA', pero persona tiene 'REGION IX METEPEC'
        $eDiscrepante = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'datos_solicitados' => ['adscripcion' => 'REGION I TOLUCA'],
        ]);

        // Elemento sin discrepancia: no tiene persona vinculada
        $eNormal = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPNORM0000000001',
            'datos_solicitados' => ['adscripcion' => 'REGION I TOLUCA'],
        ]);

        $resp = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'agrupamiento' => 'discrepante']));
        $resp->assertOk();
        $items = $resp->viewData('elementos');
        $this->assertCount(1, $items);
        $this->assertSame($eDiscrepante->id, $items->first()->id);
    }

    /** 08. Filtro C: Agrupamiento ambiguo con las cuatro denominaciones ambiguas del catálogo oficial */
    public function test_08_filtro_agrupamiento_ambiguo_y_las_cuatro_denominaciones_ambiguas(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 5]);

        // Crear homónimos con claves distintas en catálogo (las 4 denominaciones ambiguas reales identificadas)
        LocCatalogoOrganizacional::create(['clave_origen' => 'A168', 'nombre' => 'COMANDANCIA CUAUTITLAN IZCALLI', 'activo' => true]);
        LocCatalogoOrganizacional::create(['clave_origen' => 'A218', 'nombre' => 'COMANDANCIA CUAUTITLAN IZCALLI', 'activo' => true]);

        LocCatalogoOrganizacional::create(['clave_origen' => 'A172', 'nombre' => '1° AGRUPAMIENTO CUAUTITLAN IZCALLI', 'activo' => true]);
        LocCatalogoOrganizacional::create(['clave_origen' => 'A219', 'nombre' => '1° AGRUPAMIENTO CUAUTITLAN IZCALLI', 'activo' => true]);

        LocCatalogoOrganizacional::create(['clave_origen' => 'A181', 'nombre' => 'COMANDANCIA ECATEPEC', 'activo' => true]);
        LocCatalogoOrganizacional::create(['clave_origen' => 'A188', 'nombre' => 'COMANDANCIA ECATEPEC', 'activo' => true]);

        LocCatalogoOrganizacional::create(['clave_origen' => 'A215', 'nombre' => 'COMANDANCIA NEZAHUALCOYOTL', 'activo' => true]);
        LocCatalogoOrganizacional::create(['clave_origen' => 'A223', 'nombre' => 'COMANDANCIA NEZAHUALCOYOTL', 'activo' => true]);

        // Denominación unívoca de control
        LocCatalogoOrganizacional::create(['clave_origen' => 'Z999', 'nombre' => 'COMANDANCIA TOLUCA UNICA', 'activo' => true]);

        $eAmb1 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIPAMB00000000001',
            'datos_solicitados' => ['adscripcion' => 'COMANDANCIA CUAUTITLAN IZCALLI'],
        ]);

        $eAmb2 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPAMB00000000002',
            'datos_solicitados' => ['adscripcion' => '1° AGRUPAMIENTO CUAUTITLAN IZCALLI'],
        ]);

        $eAmb3 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 3,
            'cuip' => 'CUIPAMB00000000003',
            'datos_solicitados' => ['adscripcion' => 'COMANDANCIA ECATEPEC'],
        ]);

        $eAmb4 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 4,
            'cuip' => 'CUIPAMB00000000004',
            'datos_solicitados' => ['adscripcion' => 'COMANDANCIA NEZAHUALCOYOTL'],
        ]);

        $eUnivoco = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 5,
            'cuip' => 'CUIPUNIV0000000001',
            'datos_solicitados' => ['adscripcion' => 'COMANDANCIA TOLUCA UNICA'],
        ]);

        $resp = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'agrupamiento' => 'ambiguo']));
        $resp->assertOk();
        $items = $resp->viewData('elementos');

        // Los 4 ambiguos deben ser retornados
        $this->assertCount(4, $items);
        $this->assertTrue($items->pluck('id')->contains($eAmb1->id));
        $this->assertTrue($items->pluck('id')->contains($eAmb2->id));
        $this->assertTrue($items->pluck('id')->contains($eAmb3->id));
        $this->assertTrue($items->pluck('id')->contains($eAmb4->id));
        // El unívoco NUNCA debe aparecer como ambiguo
        $this->assertFalse($items->pluck('id')->contains($eUnivoco->id));
    }

    /** 09. Filtro C: Agrupamiento sin información */
    public function test_09_filtro_agrupamiento_sin_informacion(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 3]);

        // Elemento sin adscripción y sin persona
        $eSinInfo1 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIPSININF00000001',
            'datos_solicitados' => [],
        ]);

        // Elemento con persona pero persona sin agrupamiento
        $personaVacia = LocPersona::create(['nombre' => 'JUAN VACIO', 'cuip' => 'CUIPVACIO000000001', 'agrupamiento' => null]);
        $eSinInfo2 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'persona_id' => $personaVacia->id,
            'cuip' => $personaVacia->cuip,
            'datos_solicitados' => ['adscripcion' => ''],
        ]);

        // Elemento con adscripción
        $eConInfo = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 3,
            'cuip' => 'CUIPCONINFO0000001',
            'datos_solicitados' => ['adscripcion' => 'ALGUNA ADSCRIPCION'],
        ]);

        $resp = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'agrupamiento' => 'sin_informacion']));
        $resp->assertOk();
        $items = $resp->viewData('elementos');

        $this->assertCount(2, $items);
        $this->assertTrue($items->pluck('id')->contains($eSinInfo1->id));
        $this->assertTrue($items->pluck('id')->contains($eSinInfo2->id));
        $this->assertFalse($items->pluck('id')->contains($eConInfo->id));
    }

    /** 10. Demostración de equivalencia biyectiva estricta con AgrupamientoInstitucional::resolver() */
    public function test_10_demostracion_estricta_de_equivalencia_biyectiva_con_agrupamiento_institucional_resolver(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 10]);

        LocCatalogoOrganizacional::create(['clave_origen' => 'C100', 'nombre' => 'SEGURIDAD VIAL ESTATAL', 'activo' => true]);
        LocCatalogoOrganizacional::create(['clave_origen' => 'HOM1', 'nombre' => 'DELEGACION SUR', 'activo' => true]);
        LocCatalogoOrganizacional::create(['clave_origen' => 'HOM2', 'nombre' => 'DELEGACION SUR', 'activo' => true]);

        $pDiscrep = LocPersona::create(['nombre' => 'CARLOS DIS', 'cuip' => 'CUIPDIS00000000001', 'agrupamiento' => 'PATRULLAS']);
        $pCoincide = LocPersona::create(['nombre' => 'MARTA OK', 'cuip' => 'CUIPOK000000000001', 'agrupamiento' => 'SEGURIDAD VIAL ESTATAL']);

        $elementosCreados = [
            // 1. Verificado
            SolicitudElemento::create(['solicitud_id' => $solicitud->id, 'numero_fila' => 1, 'cuip' => 'CUIP1', 'datos_solicitados' => ['adscripcion' => 'SEGURIDAD VIAL ESTATAL']]),
            // 2. Verificado por persona
            SolicitudElemento::create(['solicitud_id' => $solicitud->id, 'numero_fila' => 2, 'persona_id' => $pCoincide->id, 'cuip' => $pCoincide->cuip, 'datos_solicitados' => []]),
            // 3. Ambiguo
            SolicitudElemento::create(['solicitud_id' => $solicitud->id, 'numero_fila' => 3, 'cuip' => 'CUIP3', 'datos_solicitados' => ['adscripcion' => 'DELEGACION SUR']]),
            // 4. Discrepante
            SolicitudElemento::create(['solicitud_id' => $solicitud->id, 'numero_fila' => 4, 'persona_id' => $pDiscrep->id, 'cuip' => $pDiscrep->cuip, 'datos_solicitados' => ['adscripcion' => 'TRANSITO']]),
            // 5. Sin clave
            SolicitudElemento::create(['solicitud_id' => $solicitud->id, 'numero_fila' => 5, 'cuip' => 'CUIP5', 'datos_solicitados' => ['adscripcion' => 'DIRECCION INEXISTENTE']]),
            // 6. Sin informacion
            SolicitudElemento::create(['solicitud_id' => $solicitud->id, 'numero_fila' => 6, 'cuip' => 'CUIP6', 'datos_solicitados' => []]),
        ];

        $catalogo = LocCatalogoOrganizacional::where('activo', true)->get();

        $estadosAProbar = ['verificado', 'sin_clave', 'discrepante', 'ambiguo', 'sin_informacion'];

        foreach ($estadosAProbar as $estadoFiltro) {
            $resp = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'agrupamiento' => $estadoFiltro]));
            $resp->assertOk();
            $elementosFiltrados = $resp->viewData('elementos');
            $idsFiltrados = $elementosFiltrados->pluck('id')->all();

            // Comprobar equivalencia biyectiva para CADA elemento de la solicitud
            foreach ($elementosCreados as $elem) {
                $resolved = AgrupamientoInstitucional::resolver($elem, $catalogo);
                $pertenece = in_array($elem->id, $idsFiltrados, true);

                if ($resolved['estado'] === $estadoFiltro) {
                    $this->assertTrue($pertenece, "El elemento #{$elem->numero_fila} fue clasificado por resolver como '{$resolved['estado']}', pero NO apareció en el filtro '{$estadoFiltro}'.");
                } else {
                    $this->assertFalse($pertenece, "El elemento #{$elem->numero_fila} fue clasificado por resolver como '{$resolved['estado']}', pero APARECIÓ erróneamente en el filtro '{$estadoFiltro}'.");
                }
            }
        }
    }

    /** 11. Filtro D: Búsqueda general 'q' por CUIP, nombre, adscripción, matrícula y armas oficiales */
    public function test_11_busqueda_q_encuentra_por_cuip_nombre_adscripcion_matricula_y_armas(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 5]);

        $persona = LocPersona::create(['nombre' => 'ALEJANDRO MAGNO', 'cuip' => 'CUIPBUSQ0000000001']);
        $armaCorta = LocArma::create(['matricula' => 'GLOCK999', 'marca' => 'Glock', 'modelo' => '17 Gen 5']);
        $armaLarga = LocArma::create(['matricula' => 'COLT888', 'marca' => 'Colt', 'modelo' => 'M4 Carbine']);

        $e1 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'persona_id' => $persona->id,
            'cuip' => $persona->cuip,
            'datos_solicitados' => ['nombre' => 'ALEJANDRO SOLICITADO', 'adscripcion' => 'ZONA ORIENTE'],
        ]);

        $e2 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPBUSQ0000000002',
            'matricula_corta' => 'BERETTA-SOLICITADA-123',
            'arma_corta_id' => $armaCorta->id,
        ]);

        $e3 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 3,
            'cuip' => 'CUIPBUSQ0000000003',
            'arma_larga_id' => $armaLarga->id,
        ]);

        // Búsqueda por CUIP
        $respCuip = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'q' => 'CUIPBUSQ0000000001']));
        $this->assertCount(1, $respCuip->viewData('elementos'));
        $this->assertSame($e1->id, $respCuip->viewData('elementos')->first()->id);

        // Búsqueda por Nombre Oficial
        $respNom = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'q' => 'MAGNO']));
        $this->assertCount(1, $respNom->viewData('elementos'));
        $this->assertSame($e1->id, $respNom->viewData('elementos')->first()->id);

        // Búsqueda por Adscripción solicitada
        $respAds = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'q' => 'ZONA ORIENTE']));
        $this->assertCount(1, $respAds->viewData('elementos'));
        $this->assertSame($e1->id, $respAds->viewData('elementos')->first()->id);

        // Búsqueda por Matrícula solicitada
        $respMatSol = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'q' => 'BERETTA-SOLICITADA']));
        $this->assertCount(1, $respMatSol->viewData('elementos'));
        $this->assertSame($e2->id, $respMatSol->viewData('elementos')->first()->id);

        // Búsqueda por Matrícula oficial arma corta
        $respMatOf = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'q' => 'GLOCK999']));
        $this->assertCount(1, $respMatOf->viewData('elementos'));
        $this->assertSame($e2->id, $respMatOf->viewData('elementos')->first()->id);

        // Búsqueda por Marca/Modelo arma larga
        $respModelo = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'q' => 'M4 Carbine']));
        $this->assertCount(1, $respModelo->viewData('elementos'));
        $this->assertSame($e3->id, $respModelo->viewData('elementos')->first()->id);
    }

    /** 12. Filtro D: Búsqueda 'q' escapa comodines % y _ sin generar falsos positivos */
    public function test_12_busqueda_q_escapa_comodines_porcentaje_y_guion_bajo_sin_falsos_positivos(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 2]);

        $e1 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIP100%REAL',
            'matricula_corta' => 'MAT_ESPECIAL',
        ]);

        $e2 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIP1000REAL',
            'matricula_corta' => 'MATXESPECIAL',
        ]);

        // Buscar literal '100%' solo debe traer e1, no e2
        $respPorcentaje = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'q' => '100%']));
        $this->assertCount(1, $respPorcentaje->viewData('elementos'));
        $this->assertSame($e1->id, $respPorcentaje->viewData('elementos')->first()->id);

        // Buscar literal 'MAT_' solo debe traer e1, no e2 ('MATX')
        $respGuion = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'q' => 'MAT_']));
        $this->assertCount(1, $respGuion->viewData('elementos'));
        $this->assertSame($e1->id, $respGuion->viewData('elementos')->first()->id);
    }

    /** 13. Filtro E: Armamento distingue de forma independiente corta y larga, solicitada vs vinculada */
    public function test_13_filtro_armamento_distingue_independiente_corta_y_larga_solicitada_vs_oficial(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 4]);

        $armaOficial = LocArma::create(['matricula' => 'OFICIAL-100', 'marca' => 'Glock']);

        // e1: con arma corta vinculada
        $e1 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIPARM00000000001',
            'matricula_corta' => 'OFICIAL-100',
            'arma_corta_id' => $armaOficial->id,
        ]);

        // e2: con arma corta solicitada pero NO vinculada
        $e2 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPARM00000000002',
            'matricula_corta' => 'SOLO-SOLICITADA-200',
            'arma_corta_id' => null,
        ]);

        // e3: sin arma corta
        $e3 = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 3,
            'cuip' => 'CUIPARM00000000003',
            'matricula_corta' => null,
            'arma_corta_id' => null,
            'matricula_larga' => 'LARGA-SOLICITADA-300',
        ]);

        // Filtro arma corta vinculada -> solo e1
        $respVinc = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'arma_corta' => 'vinculada']));
        $this->assertCount(1, $respVinc->viewData('elementos'));
        $this->assertSame($e1->id, $respVinc->viewData('elementos')->first()->id);

        // Filtro arma corta no vinculada -> solo e2
        $respNoVinc = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'arma_corta' => 'no_vinculada']));
        $this->assertCount(1, $respNoVinc->viewData('elementos'));
        $this->assertSame($e2->id, $respNoVinc->viewData('elementos')->first()->id);

        // Filtro con arma corta -> e1 y e2
        $respConCorta = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'arma_corta' => 'con_arma']));
        $this->assertCount(2, $respConCorta->viewData('elementos'));

        // Filtro sin arma corta -> e3
        $respSinCorta = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'arma_corta' => 'sin_arma']));
        $this->assertCount(1, $respSinCorta->viewData('elementos'));
        $this->assertSame($e3->id, $respSinCorta->viewData('elementos')->first()->id);

        // Filtro con arma larga -> e3
        $respConLarga = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'arma_larga' => 'con_arma']));
        $this->assertCount(1, $respConLarga->viewData('elementos'));
        $this->assertSame($e3->id, $respConLarga->viewData('elementos')->first()->id);
    }

    /** 14. Combinación simultánea de múltiples filtros opera con lógica AND estricta */
    public function test_14_combinacion_simultanea_de_multiples_filtros_opera_con_and_estricto(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 4]);

        LocCatalogoOrganizacional::create(['clave_origen' => 'A100', 'nombre' => 'SEGURIDAD VIAL TOLUCA', 'activo' => true]);

        // Elemento que cumple TODO: Descartado, ARM_SIN_ASIGNACION, Clave A100, sin arma corta, término 'TOLUCA'
        $eTarget = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIPTARGET00000001',
            'resultado_prefiltro' => 'Descartado',
            'codigos_descarte' => [MotivoDescarte::ARM_SIN_ASIGNACION],
            'datos_solicitados' => ['adscripcion' => 'SEGURIDAD VIAL TOLUCA'],
            'matricula_corta' => null,
        ]);

        // Elemento que falla en motivo (tiene CUIP_VACIO)
        $eFallaMotivo = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 2,
            'cuip' => 'CUIPFALLA000000001',
            'resultado_prefiltro' => 'Descartado',
            'codigos_descarte' => [MotivoDescarte::CUIP_VACIO],
            'datos_solicitados' => ['adscripcion' => 'SEGURIDAD VIAL TOLUCA'],
            'matricula_corta' => null,
        ]);

        // Elemento que falla en prefiltro (es Apto)
        $eFallaPrefiltro = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 3,
            'cuip' => 'CUIPFALLA000000002',
            'resultado_prefiltro' => 'Apto',
            'datos_solicitados' => ['adscripcion' => 'SEGURIDAD VIAL TOLUCA'],
            'matricula_corta' => null,
        ]);

        // Petición con 5 filtros simultáneos combinados
        $resp = $this->get(route('credencializacion.detalle', [
            'solicitud' => $solicitud->id,
            'prefiltro' => 'descartados',
            'motivo' => MotivoDescarte::ARM_SIN_ASIGNACION,
            'agrupamiento' => 'verificado',
            'clave' => 'A100',
            'arma_corta' => 'sin_arma',
            'q' => 'TOLUCA',
        ]));

        $resp->assertOk();
        $items = $resp->viewData('elementos');

        $this->assertCount(1, $items);
        $this->assertSame($eTarget->id, $items->first()->id);
    }

    /** 15. Paginación conserva el query string y los enlaces entre páginas */
    public function test_15_paginacion_conserva_query_string_y_conteo_reactivo(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 60]);

        // Crear 55 descartados y 5 aptos
        for ($i = 1; $i <= 55; $i++) {
            SolicitudElemento::create([
                'solicitud_id' => $solicitud->id,
                'numero_fila' => $i,
                'cuip' => sprintf('CUIPDESC%010d', $i),
                'resultado_prefiltro' => 'Descartado',
                'codigos_descarte' => [MotivoDescarte::ARM_SIN_ASIGNACION],
            ]);
        }

        for ($i = 56; $i <= 60; $i++) {
            SolicitudElemento::create([
                'solicitud_id' => $solicitud->id,
                'numero_fila' => $i,
                'cuip' => sprintf('CUIPAPTO%010d', $i),
                'resultado_prefiltro' => 'Apto',
            ]);
        }

        // Página 1 filtrada por descartados
        $respP1 = $this->get(route('credencializacion.detalle', [
            'solicitud' => $solicitud->id,
            'prefiltro' => 'descartados',
            'page' => 1,
        ]));

        $respP1->assertOk();
        $elementosP1 = $respP1->viewData('elementos');
        $this->assertCount(50, $elementosP1);
        $this->assertSame(55, $elementosP1->total());

        // Verificar que los links del paginador preservan 'prefiltro=descartados'
        $respP1->assertSee('prefiltro=descartados');

        // Página 2 filtrada por descartados
        $respP2 = $this->get(route('credencializacion.detalle', [
            'solicitud' => $solicitud->id,
            'prefiltro' => 'descartados',
            'page' => 2,
        ]));

        $respP2->assertOk();
        $elementosP2 = $respP2->viewData('elementos');
        $this->assertCount(5, $elementosP2);
        $this->assertSame(55, $elementosP2->total());
    }

    /** 16. Limpiar filtros devuelve el expediente completo y totales generales permanecen inmutables */
    public function test_16_limpiar_filtros_devuelve_expediente_completo_y_totales_generales_permanecen_inmutables(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud([
            'total_elementos' => 10,
            'total_aptos' => 4,
            'total_descartados' => 6,
        ]);

        for ($i = 1; $i <= 10; $i++) {
            SolicitudElemento::create([
                'solicitud_id' => $solicitud->id,
                'numero_fila' => $i,
                'cuip' => sprintf('CUIPEXPD%010d', $i),
                'resultado_prefiltro' => $i <= 4 ? 'Apto' : 'Descartado',
            ]);
        }

        // Con filtro activo
        $respFiltrado = $this->get(route('credencializacion.detalle', ['solicitud' => $solicitud->id, 'prefiltro' => 'aptos']));
        $respFiltrado->assertOk();
        $this->assertCount(4, $respFiltrado->viewData('elementos'));
        // Totales de la cabecera en el modelo NO mutaron
        $this->assertSame(10, $respFiltrado->viewData('solicitud')->total_elementos);
        $this->assertSame(4, $respFiltrado->viewData('solicitud')->total_aptos);
        $this->assertSame(6, $respFiltrado->viewData('solicitud')->total_descartados);

        // Sin parámetros (equivalente a Limpiar filtros)
        $respLimpio = $this->get(route('credencializacion.detalle', $solicitud->id));
        $respLimpio->assertOk();
        $this->assertCount(10, $respLimpio->viewData('elementos'));
        $this->assertSame(10, $respLimpio->viewData('solicitud')->total_elementos);
    }

    /** 17. Ausencia de duplicados cartesianos y prevención de N+1 queries */
    public function test_17_ausencia_de_duplicados_cartesianos_y_prevencion_de_n_plus_one(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 5]);

        $personaConVariasArmas = LocPersona::create(['nombre' => 'MARIO BENEDETTI', 'cuip' => 'CUIPMARIO000000001']);

        // Persona asociada a múltiples registros en padrones
        $e = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'persona_id' => $personaConVariasArmas->id,
            'cuip' => $personaConVariasArmas->cuip,
            'datos_solicitados' => ['nombre' => 'MARIO BENEDETTI'],
        ]);

        // Búsqueda multicampo que involucra subconsultas whereHas
        $resp = $this->get(route('credencializacion.detalle', [
            'solicitud' => $solicitud->id,
            'q' => 'BENEDETTI',
        ]));

        $resp->assertOk();
        $items = $resp->viewData('elementos');

        // Cardinalidad exacta: exactamente 1 resultado, cero duplicaciones por subconsultas
        $this->assertCount(1, $items);
        $this->assertSame($e->id, $items->first()->id);

        // Medir número de consultas ejecutadas para comprobar ausencia de N+1
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('credencializacion.detalle', $solicitud->id))->assertOk();

        $queryCount = count(DB::getQueryLog());
        // Consultas esperadas: solicitud + elementos paginados + eager load (persona, armas) + catalogo = acotado a ≤ 10 queries
        $this->assertLessThanOrEqual(10, $queryCount, "Se detectó potencial N+1: se ejecutaron {$queryCount} consultas para el detalle.");
    }

    /** 18. Filtros son de estricta lectura y preservan updated_at y datos históricos */
    public function test_18_filtros_son_estrictamente_de_lectura_y_preservan_updated_at_y_datos_historicos(): void
    {
        $this->actingAs($this->user);
        $solicitud = $this->createSolicitud(['total_elementos' => 1]);

        $fechaFija = now()->subDays(10)->startOfMinute();

        $elem = SolicitudElemento::create([
            'solicitud_id' => $solicitud->id,
            'numero_fila' => 1,
            'cuip' => 'CUIPINMUTABLE00001',
            'resultado_prefiltro' => 'Descartado',
            'codigos_descarte' => [MotivoDescarte::ARM_SIN_ASIGNACION],
        ]);

        DB::table('solicitud_elementos')->where('id', $elem->id)->update([
            'created_at' => $fechaFija,
            'updated_at' => $fechaFija,
        ]);

        $elem->refresh();

        // Ejecutar consultas con varios filtros
        $this->get(route('credencializacion.detalle', [
            'solicitud' => $solicitud->id,
            'prefiltro' => 'descartados',
            'motivo' => MotivoDescarte::ARM_SIN_ASIGNACION,
            'q' => 'CUIPINMUTABLE',
        ]))->assertOk();

        $elem->refresh();

        // Verificar que los datos y marcas de tiempo no fueron alterados en lo absoluto
        $this->assertEquals($fechaFija->toDateTimeString(), $elem->updated_at->toDateTimeString());
        $this->assertSame(['ARM_SIN_ASIGNACION'], $elem->codigos_descarte);
        $this->assertSame('Descartado', $elem->resultado_prefiltro);
    }
}
