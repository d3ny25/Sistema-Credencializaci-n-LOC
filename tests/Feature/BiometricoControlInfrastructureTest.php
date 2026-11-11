<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoCategoria;
use App\Enums\BiometricoIncidenciaSeveridad;
use App\Enums\BiometricoIncidenciaTipo;
use App\Enums\BiometricoIndexRunEstado;
use App\Models\BiometricoIncidencia;
use App\Models\BiometricoIndexRun;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BiometricoControlInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    protected function crearUsuario(string $role = 'admin'): User
    {
        $user = new User();
        $user->name = 'Operador ' . ucfirst($role);
        $user->email = 'test_' . $role . '_' . uniqid() . '@example.com';
        $user->password = bcrypt('secret123');
        $user->role = $role;
        $user->save();

        return $user;
    }

    public function test_crea_ejecucion_de_indexacion_con_uuid_y_valores_por_defecto(): void
    {
        $run = BiometricoIndexRun::create([
            'ambito' => 'ESTATALES',
        ]);

        $this->assertNotEmpty($run->uuid);
        $this->assertSame(36, strlen($run->uuid));
        $this->assertSame(BiometricoIndexRunEstado::PENDIENTE, $run->estado);
        $this->assertSame(0, $run->total_archivos_detectados);
        $this->assertSame(0, $run->total_archivos_procesados);
        $this->assertSame(0, $run->total_incidencias);
        $this->assertFalse($run->es_dry_run);
        $this->assertFalse($run->estado->isFinalizada());
    }

    public function test_flujo_de_estados_de_ejecucion_con_duracion_y_metricas(): void
    {
        $user = $this->crearUsuario('admin');
        $run = BiometricoIndexRun::create([
            'ambito' => 'PREVENCION',
            'iniciado_por_user_id' => $user->id,
        ]);

        // Marcar inicio
        $run->marcarInicio();
        $this->assertSame(BiometricoIndexRunEstado::EN_EJECUCION, $run->estado);
        $this->assertNotNull($run->iniciado_at);

        // Simular avance y checkpoint
        $run->update([
            'ultimo_directorio_procesado' => 'PREVENCION/FOTOS',
            'ultima_ruta_procesada' => 'PREVENCION/FOTOS/AAAA690916H15136329.JPG',
            'total_archivos_detectados' => 100,
            'total_archivos_procesados' => 100,
            'total_archivos_nuevos' => 98,
            'memoria_pico_mb' => 24.50,
        ]);

        // Marcar finalizada exitosamente
        $run->marcarFinalizada();
        $this->assertSame(BiometricoIndexRunEstado::COMPLETADA, $run->estado);
        $this->assertNotNull($run->finalizado_at);
        $this->assertTrue($run->estado->isFinalizada());
        $this->assertTrue($run->estado->esExitosa());
    }

    public function test_registra_interrupcion_con_motivo_y_punto_de_control(): void
    {
        $run = BiometricoIndexRun::create(['ambito' => 'TODOS']);
        $run->marcarInicio();

        $run->marcarInterrumpida('Interrupción manual por operador', 'ESTATALES/Firmas/CHECKPOINT.png');

        $this->assertSame(BiometricoIndexRunEstado::INTERRUMPIDA, $run->estado);
        $this->assertSame('Interrupción manual por operador', $run->resumen_interrupcion);
        $this->assertSame('ESTATALES/Firmas/CHECKPOINT.png', $run->ultima_ruta_procesada);
        $this->assertTrue($run->estado->permiteReanudacion());
    }

    public function test_registra_fallo_critico_con_mensaje_de_error(): void
    {
        $run = BiometricoIndexRun::create(['ambito' => 'ESTATALES']);
        $run->marcarInicio();

        $run->marcarFallida('Error de conexión con el almacenamiento');

        $this->assertSame(BiometricoIndexRunEstado::FALLIDA, $run->estado);
        $this->assertSame('Error de conexión con el almacenamiento', $run->error_mensaje);
        $this->assertTrue($run->estado->permiteReanudacion());
    }

    public function test_crea_incidencias_vinculadas_e_incrementa_contador_atomicamente(): void
    {
        $run = BiometricoIndexRun::create(['ambito' => 'ESTATALES']);

        $incidencia1 = BiometricoIncidencia::create([
            'biometrico_index_run_id' => $run->id,
            'tipo' => BiometricoIncidenciaTipo::NOMBRE_INVALIDO,
            'severidad' => BiometricoIncidenciaSeveridad::ALTA,
            'codigo' => 'NOMBRE_GUID_SIN_CUIP',
            'ambito' => BiometricoAmbito::ESTATALES,
            'categoria' => BiometricoCategoria::PULGAR_DERECHO,
            'ruta_relativa' => 'ESTATALES/PULGAR DERECHO/{00987e0b-071b-4325-9d94-cb0a0610037f}.jpg',
            'nombre_archivo' => '{00987e0b-071b-4325-9d94-cb0a0610037f}.jpg',
            'descripcion' => 'Archivo nombrado con GUID de hardware sin CUIP',
            'detalles' => ['hardware_uuid' => '00987e0b-071b-4325-9d94-cb0a0610037f'],
        ]);

        $incidencia2 = BiometricoIncidencia::create([
            'biometrico_index_run_id' => $run->id,
            'tipo' => BiometricoIncidenciaTipo::CUIP_INVALIDO,
            'severidad' => BiometricoIncidenciaSeveridad::MEDIA,
            'codigo' => 'PATRON_CUIP_CORTO_RFC',
            'ambito' => BiometricoAmbito::ESTATALES,
            'categoria' => BiometricoCategoria::FIRMA,
            'ruta_relativa' => 'ESTATALES/Firmas/AAAA820529.PNG',
            'nombre_archivo' => 'AAAA820529.PNG',
            'cuip_detectado' => 'AAAA820529',
            'descripcion' => 'Archivo con longitud de 10 caracteres',
        ]);

        // El contador en el run debe haberse incrementado a 2 automáticamente
        $run->refresh();
        $this->assertSame(2, $run->total_incidencias);

        $this->assertCount(2, $run->incidencias);
        $this->assertSame($run->id, $incidencia1->run->id);

        // Marcar finalizada debe derivar en COMPLETADA_CON_INCIDENCIAS
        $run->marcarFinalizada();
        $this->assertSame(BiometricoIndexRunEstado::COMPLETADA_CON_INCIDENCIAS, $run->estado);
    }

    public function test_restrict_on_delete_impide_borrar_ejecucion_con_incidencias_asociadas(): void
    {
        $run = BiometricoIndexRun::create(['ambito' => 'ESTATALES']);

        BiometricoIncidencia::create([
            'biometrico_index_run_id' => $run->id,
            'tipo' => BiometricoIncidenciaTipo::ARCHIVO_ILEGIBLE,
            'severidad' => BiometricoIncidenciaSeveridad::CRITICA,
            'codigo' => 'ERR_FILE_UNREADABLE',
            'ruta_relativa' => 'ESTATALES/FOTOS/corrupto.jpg',
            'nombre_archivo' => 'corrupto.jpg',
            'descripcion' => 'No fue posible abrir descriptor de archivo',
        ]);

        $this->expectException(QueryException::class);
        $run->delete(); // Debe fallar por restricción de integridad referencial
    }

    public function test_scopes_de_consulta_en_runs_e_incidencias(): void
    {
        $run1 = BiometricoIndexRun::create(['ambito' => 'ESTATALES', 'estado' => BiometricoIndexRunEstado::EN_EJECUCION]);
        $run2 = BiometricoIndexRun::create(['ambito' => 'PREVENCION', 'estado' => BiometricoIndexRunEstado::COMPLETADA]);

        $this->assertCount(1, BiometricoIndexRun::activas()->get());
        $this->assertCount(1, BiometricoIndexRun::finalizadas()->get());
        $this->assertCount(1, BiometricoIndexRun::byAmbito(BiometricoAmbito::ESTATALES)->get());

        BiometricoIncidencia::create([
            'biometrico_index_run_id' => $run1->id,
            'tipo' => BiometricoIncidenciaTipo::CONFLICTO_IDENTIDAD,
            'severidad' => BiometricoIncidenciaSeveridad::CRITICA,
            'codigo' => 'DISCREPANCIA_CUIP',
            'ruta_relativa' => 'test.jpg',
            'nombre_archivo' => 'test.jpg',
            'cuip_detectado' => 'TEST12345678901234',
            'descripcion' => 'Discrepancia crítica',
        ]);

        $this->assertCount(1, BiometricoIncidencia::criticas()->get());
        $this->assertCount(1, BiometricoIncidencia::byCuip('TEST12345678901234')->get());
        $this->assertCount(1, BiometricoIncidencia::byTipo(BiometricoIncidenciaTipo::CONFLICTO_IDENTIDAD)->get());
    }
}

