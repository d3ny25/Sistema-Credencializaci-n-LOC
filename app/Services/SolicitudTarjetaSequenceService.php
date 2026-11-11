<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\SequenceNotInitializedException;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use OverflowException;

class SolicitudTarjetaSequenceService
{
    public const DEFAULT_SEQUENCE_KEY = 'tarjeta_credencial';

    /**
     * Genera de forma atómica y transaccional el siguiente número de tarjeta de credencialización
     * y su consecutivo numérico correspondiente.
     *
     * @param DateTimeInterface|null $fecha Fecha de referencia para el año de expedición.
     * @return array{numero_tarjeta: string, consecutivo_tarjeta: int}
     *
     * @throws SequenceNotInitializedException Si la secuencia no existe en la base de datos.
     * @throws OverflowException Si el consecutivo supera el límite entero soportado.
     */
    public function generateNextNumber(?DateTimeInterface $fecha = null): array
    {
        $clave = (string) config('credencializacion.secuencia_clave', self::DEFAULT_SEQUENCE_KEY);
        $prefijo = (string) config('credencializacion.tarjeta_prefijo', 'CE/TA');
        $periodo = (string) config('credencializacion.tarjeta_periodo', '25-27');
        $consecutivoInicial = (int) config('credencializacion.tarjeta_consecutivo_inicial', 50001);
        $minDigits = (int) config('credencializacion.consecutivo_min_digits', 5);

        // Bloqueo pesimista de fila en InnoDB
        $seq = DB::table('solicitud_secuencias')
            ->where('clave', $clave)
            ->lockForUpdate()
            ->first();

        if ($seq === null) {
            throw new SequenceNotInitializedException($clave);
        }

        $ultimo = (int) $seq->ultimo_consecutivo;
        $siguiente = $ultimo + 1;

        // Asegurar que nunca sea menor al piso institucional configurado (50001)
        if ($siguiente < $consecutivoInicial) {
            $siguiente = $consecutivoInicial;
        }

        if ($siguiente < 0) {
            throw new OverflowException("El consecutivo numérico ha superado el límite de enteros soportado.");
        }

        // Actualizar el contador en la misma transacción
        DB::table('solicitud_secuencias')
            ->where('clave', $clave)
            ->update([
                'ultimo_consecutivo' => $siguiente,
                'updated_at' => now(),
            ]);

        // Construcción del número formateado inmutable
        $anioFijo = config('credencializacion.anio_expedicion_fijo');
        $anio = $anioFijo !== null ? (string) $anioFijo : ($fecha ?? now())->format('Y');

        $numeroTarjeta = $this->formatCardNumber($siguiente, $prefijo, $periodo, $anio, $minDigits);

        return [
            'numero_tarjeta' => $numeroTarjeta,
            'consecutivo_tarjeta' => $siguiente,
        ];
    }

    /**
     * Construye la representación textual institucional del número de tarjeta.
     */
    public function formatCardNumber(
        int $consecutivo,
        ?string $prefijo = null,
        ?string $periodo = null,
        ?string $anio = null,
        int $minDigits = 5
    ): string {
        $prefijo = $prefijo ?? (string) config('credencializacion.tarjeta_prefijo', 'CE/TA');
        $periodo = $periodo ?? (string) config('credencializacion.tarjeta_periodo', '25-27');
        $anio = $anio ?? now()->format('Y');

        $consecutivoPadded = str_pad((string) $consecutivo, max(1, $minDigits), '0', STR_PAD_LEFT);

        return sprintf('%s%s/%s/%s', $prefijo, $periodo, $consecutivoPadded, $anio);
    }

    /**
     * Consulta de solo lectura del último consecutivo registrado sin bloquear la fila.
     */
    public function getCurrentConsecutive(?string $clave = null): ?int
    {
        $clave = $clave ?? (string) config('credencializacion.secuencia_clave', self::DEFAULT_SEQUENCE_KEY);

        $val = DB::table('solicitud_secuencias')
            ->where('clave', $clave)
            ->value('ultimo_consecutivo');

        return $val !== null ? (int) $val : null;
    }
}

