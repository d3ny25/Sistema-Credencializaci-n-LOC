<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Inicializa la secuencia de folios con el último folio institucional ya utilizado
     * (config credencializacion.folio_ultimo_consecutivo; OM2527E00000 → 0).
     * Si la clave ya existe no se toca: nunca reinicia una secuencia en uso.
     */
    public function up(): void
    {
        DB::table('solicitud_secuencias')->insertOrIgnore([
            'clave' => (string) config('credencializacion.folio_secuencia_clave', 'folio_credencial'),
            'ultimo_consecutivo' => (int) config('credencializacion.folio_ultimo_consecutivo', 0),
            'descripcion' => 'Secuencia global institucional de folios de credencial LOC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Solo elimina la secuencia si nunca asignó folios; si ya se usó, se conserva.
     */
    public function down(): void
    {
        DB::table('solicitud_secuencias')
            ->where('clave', (string) config('credencializacion.folio_secuencia_clave', 'folio_credencial'))
            ->where('ultimo_consecutivo', (int) config('credencializacion.folio_ultimo_consecutivo', 0))
            ->delete();
    }
};
