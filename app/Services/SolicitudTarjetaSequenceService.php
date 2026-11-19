<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\SequenceNotInitializedException;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use LogicException;
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

        // Nunca menor al piso institucional configurado (50001).
        $siguiente = $this->reservarSiguiente($clave, $consecutivoInicial);

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
     * Reserva el siguiente folio institucional de credencial (p. ej. OM2527E00001).
     * Debe llamarse dentro de la transacción que crea la credencial: si esta falla,
     * el incremento se revierte y no quedan huecos.
     *
     * @throws SequenceNotInitializedException Si la secuencia de folios no existe.
     * @throws LogicException Si se invoca fuera de una transacción.
     */
    public function generateNextFolio(): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('El folio de credencial solo puede reservarse dentro de una transacción.');
        }

        $clave = (string) config('credencializacion.folio_secuencia_clave', 'folio_credencial');

        return $this->formatFolio($this->reservarSiguiente($clave, 1));
    }

    /**
     * Representación institucional del folio: {prefijo}{periodo sin guion}{separador}{consecutivo}.
     */
    public function formatFolio(int $consecutivo): string
    {
        $periodo = preg_replace('/\D/', '', (string) config('credencializacion.tarjeta_periodo', '25-27'));
        $digitos = max(1, (int) config('credencializacion.folio_digitos', 5));

        return sprintf(
            '%s%s%s%s',
            (string) config('credencializacion.folio_prefijo', 'OM'),
            $periodo,
            (string) config('credencializacion.folio_separador', 'E'),
            str_pad((string) $consecutivo, $digitos, '0', STR_PAD_LEFT)
        );
    }

    /**
     * Incrementa la secuencia con bloqueo de fila (SELECT … FOR UPDATE) y devuelve el nuevo consecutivo.
     *
     * @throws SequenceNotInitializedException Si la clave no existe.
     * @throws OverflowException Si el consecutivo desborda el entero soportado.
     */
    private function reservarSiguiente(string $clave, int $piso): int
    {
        $seq = DB::table('solicitud_secuencias')
            ->where('clave', $clave)
            ->lockForUpdate()
            ->first();

        if ($seq === null) {
            throw new SequenceNotInitializedException($clave);
        }

        $siguiente = max((int) $seq->ultimo_consecutivo + 1, $piso);

        if ($siguiente < 0) {
            throw new OverflowException('El consecutivo numérico ha superado el límite de enteros soportado.');
        }

        DB::table('solicitud_secuencias')
            ->where('clave', $clave)
            ->update([
                'ultimo_consecutivo' => $siguiente,
                'updated_at' => now(),
            ]);

        return $siguiente;
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

