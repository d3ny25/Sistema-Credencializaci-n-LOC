<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\EstadoEmision;
use App\Models\CredencialEmitida;
use App\Models\CredencialEntregaDetalle;
use App\Models\CredencialImpresion;
use App\Models\CredencialImpresionRectificacion;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Services\SeguimientoTarjetaService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Clasificación de las bandejas (Iteración 1.2) sobre relaciones ya cargadas: sin base de datos ni consultas.
 */
class SeguimientoBandejasTest extends TestCase
{
    private int $id = 0;

    private function impresion(string $resultado, int $intento, ?string $rectificadoA = null, bool $entregada = false): CredencialImpresion
    {
        $impresion = (new CredencialImpresion)->forceFill(['id' => ++$this->id, 'resultado' => $resultado, 'numero_intento' => $intento, 'modalidad_confirmacion' => 'normal', 'created_at' => now()->addMinutes($intento)]);
        $rectificaciones = $rectificadoA === null ? [] : [(new CredencialImpresionRectificacion)->forceFill(['id' => ++$this->id, 'resultado_rectificado' => $rectificadoA])];
        $detalle = $entregada ? (new CredencialEntregaDetalle)->forceFill(['id' => ++$this->id, 'credencial_impresion_id' => $impresion->id]) : null;

        return $impresion->setRelation('rectificaciones', collect($rectificaciones))->setRelation('preparacion', null)->setRelation('usuario', null)->setRelation('entregaDetalle', $detalle);
    }

    public function test_estado_de_entrega_aplica_la_regla_de_una_impresion_efectiva_por_elemento(): void
    {
        $emitida = EstadoEmision::EMITIDA->value;
        $casos = [
            'disponible' => $this->elemento('Apto', 'listo_impresion', [$this->impresion('fallida', 1), $this->impresion('impresa', 2)], $emitida),
            'anomalia_multiple' => $this->elemento('Apto', 'listo_impresion', [$this->impresion('impresa', 1), $this->impresion('impresa', 2)], $emitida),
            'entregada' => $this->elemento('Apto', 'listo_impresion', [$this->impresion('impresa', 1), $this->impresion('impresa', 2, entregada: true)], $emitida),
            'no_emitida' => $this->elemento('Apto', 'listo_impresion', [$this->impresion('fallida', 1, 'impresa')], EstadoEmision::PENDIENTE->value),
            'sin_impresion' => $this->elemento('Apto', 'listo_impresion', [$this->impresion('impresa', 1, 'fallida')], $emitida),
        ];

        DB::listen(fn ($consulta) => $this->fail('Consulta inesperada: '.$consulta->sql));
        $servicio = new SeguimientoTarjetaService;
        foreach ($casos as $esperado => $elemento) {
            $estado = $servicio->estadoEntrega($elemento);
            $this->assertSame($esperado, $estado['estado'], $esperado);
            // Solo la situación ordinaria ofrece una tarjeta; la anomalía nunca elige una automáticamente.
            $this->assertSame(in_array($esperado, ['disponible', 'entregada'], true), $estado['impresion'] !== null, $esperado);
        }
        $this->assertSame(2, $servicio->estadoEntrega($casos['disponible'])['impresion']->numero_intento);
    }

    private function elemento(?string $prefiltro, string $tramite, array $impresiones = [], ?string $emision = null): SolicitudElemento
    {
        $elemento = (new SolicitudElemento)->forceFill(['id' => ++$this->id, 'resultado_prefiltro' => $prefiltro, 'estado_tramite' => $tramite]);
        $credencial = $emision === null ? null : (new CredencialEmitida)->forceFill(['id' => ++$this->id, 'estado_emision' => $emision, 'folio' => 'FOL-'.$this->id])
            ->setRelation('impresiones', collect($impresiones))->setRelation('preparaciones', collect());

        return $elemento->setRelation('credencialEmitida', $credencial);
    }

    public function test_clasifica_cada_elemento_en_una_sola_categoria_sin_consultar_la_base(): void
    {
        $emitida = EstadoEmision::EMITIDA->value;
        $reimpreso = $this->elemento('Apto', 'listo_impresion', [$this->impresion('impresa', 1), $this->impresion('impresa', 2)], $emitida);
        $rectificado = $this->elemento('Apto', 'listo_impresion', [$this->impresion('impresa', 1, 'fallida')], $emitida);
        $fallido = $this->elemento('Apto', 'listo_impresion', [$this->impresion('fallida', 1)], EstadoEmision::PENDIENTE->value);
        $biometrico = $this->elemento('Apto', 'pendiente_biometrico');
        $descartado = $this->elemento('Descartado', 'descartado');
        $sinPrefiltro = $this->elemento(null, 'recibido');
        $tarjeta = (new SolicitudCredencial)->forceFill(['estado_general' => 'enviada_impresion'])
            ->setRelation('elementos', collect([$reimpreso, $rectificado, $fallido, $biometrico, $descartado, $sinPrefiltro]));

        DB::listen(fn ($consulta) => $this->fail('Consulta inesperada: '.$consulta->sql));
        $servicio = new SeguimientoTarjetaService;
        $recibidas = $servicio->credencialesRecibidas($tarjeta);
        $pendientes = $servicio->pendientes($tarjeta)->keyBy(fn (array $f) => $f['elemento']->id);

        // Solo el reimpreso: un renglón con dos tarjetas físicas efectivas, sin duplicar el elemento.
        $this->assertSame([$reimpreso->id], $recibidas->map(fn (array $f) => $f['elemento']->id)->all());
        $this->assertSame(2, $recibidas->first()['efectivas']);
        $this->assertCount(2, $recibidas->first()['intentos']);

        $this->assertSame('loc', $pendientes[$reimpreso->id]['categoria']);
        $this->assertSame('impresion', $pendientes[$rectificado->id]['categoria']);
        $this->assertStringContainsString('rectificada como fallida', $pendientes[$rectificado->id]['motivo']);
        $this->assertSame('impresion', $pendientes[$fallido->id]['categoria']);
        $this->assertSame('biometricos', $pendientes[$biometrico->id]['categoria']);
        $this->assertArrayNotHasKey($descartado->id, $pendientes->all());
        $this->assertArrayNotHasKey($sinPrefiltro->id, $pendientes->all());
        $this->assertTrue($recibidas->first()['intentos']->every(fn (array $i) => $i['rectificada'] === false));
    }
}
