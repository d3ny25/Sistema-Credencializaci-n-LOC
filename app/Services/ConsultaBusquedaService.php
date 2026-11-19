<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CredencialEmitida;
use App\Models\LocArma;
use App\Models\LocPersona;
use App\Models\LocVigenciaCredencial;
use App\Models\SolicitudCredencial;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Consultas (C1): búsqueda de solo lectura sobre las tablas confirmadas (nunca staging). Cada entidad se consulta
 * por separado con columnas fijas: la detección del identificador solo orienta, no decide qué tabla se consulta.
 * La intercalación utf8mb4_unicode_ci ya compara sin distinguir mayúsculas ni acentos.
 */
class ConsultaBusquedaService
{
    public const CONTEXTOS = ['todo' => 'Todo', 'elementos' => 'Elementos', 'armamento' => 'Armamento', 'tarjetas' => 'Tarjetas'];

    /** Grupo de resultados => contexto que lo incluye. Folios y credenciales LOC son entidades distintas de la tarjeta. */
    public const GRUPOS = ['elementos' => 'elementos', 'armamento' => 'armamento', 'tarjetas' => 'tarjetas', 'folios' => 'tarjetas', 'vigencias' => 'tarjetas'];

    private const POR_PAGINA = 10;

    /** @return array<string, LengthAwarePaginator> grupo => resultados paginados (paginador propio por grupo) */
    public function buscar(string $q, string $contexto): array
    {
        $grupos = array_keys(array_filter(self::GRUPOS, static fn (string $c): bool => $contexto === 'todo' || $c === $contexto));

        return array_combine($grupos, array_map(fn (string $g): LengthAwarePaginator => $this->paginar($this->{$g}($q), $g), $grupos));
    }

    /** Detección orientativa (solo ayuda visual). */
    public function detectar(string $q): ?string
    {
        $u = mb_strtoupper($q);
        $folio = '/^'.preg_quote((string) config('credencializacion.folio_prefijo', 'OM'), '/').'\d*'.preg_quote((string) config('credencializacion.folio_separador', 'E'), '/').'\d+$/';

        return match (true) {
            (bool) preg_match('/^[A-Z]{4}\d{6}[HMX][A-Z]{5}[A-Z0-9]\d$/', $u) => 'CURP',
            str_starts_with($u, mb_strtoupper((string) config('credencializacion.tarjeta_prefijo', 'CE/TA'))) => 'Número de tarjeta',
            (bool) preg_match($folio, $u) => 'Folio de credencial',
            (bool) preg_match('/^[A-ZÑ&]{4}\d{6}([A-Z0-9]{3})?$/u', $u) => 'RFC',
            (bool) preg_match('/^(?=.*\d)(?=.*[A-Z])[A-Z0-9]{18,22}$/', $u) => 'CUIP',
            (bool) preg_match('/^\d{2,5}$/', $u) => 'ID_PERSONAL o ID_ARMAMENTO',
            (bool) preg_match('/^[\pL]+( [\pL]+)+$/u', $u) => 'Nombre',
            default => null,
        };
    }

    private function elementos(string $q): Builder
    {
        $terminos = $this->terminosNombre($q);

        return LocPersona::query()
            ->select(['id', 'cuip', 'nombre', 'curp', 'region', 'municipio', 'estatus'])
            ->where(function (Builder $w) use ($q, $terminos): void {
                $w->where('cuip', $q)->orWhere('curp', $q)->orWhere('rfc', $q)->orWhere('personal_id', $q);
                if (mb_strlen($q) >= 8) {
                    $w->orWhere('cuip', 'like', $this->prefijo($q));
                }
                if (mb_strlen($q) >= 10) {
                    $w->orWhere('rfc', 'like', $this->prefijo($q));
                }
                if ($terminos !== []) {
                    // Todos los términos deben aparecer: "juan perez" no devuelve a todos los "juan".
                    $w->orWhere(function (Builder $n) use ($terminos): void {
                        foreach ($terminos as $t) {
                            $n->where('nombre', 'like', '%'.addcslashes($t, '%_\\').'%');
                        }
                    });
                }
            })
            ->orderBy('nombre');
    }

    private function armamento(string $q): Builder
    {
        return LocArma::query()
            ->select(['id', 'matricula', 'id_armamento', 'tipo_arma', 'clase_arma', 'marca', 'calibre', 'modelo', 'estatus', 'situacion'])
            ->where(function (Builder $w) use ($q): void {
                $w->where('matricula', $q)->orWhere('id_armamento', $q);
                if (mb_strlen($q) >= 4) {
                    $w->orWhere('matricula', 'like', $this->prefijo($q));
                }
            })
            ->orderBy('matricula');
    }

    private function tarjetas(string $q): Builder
    {
        // shortcut: LIKE '%…%' no usa el índice único; aceptable con pocas solicitudes, cambiar a prefijo si crecen.
        $contiene = '%'.addcslashes($q, '%_\\').'%';

        return SolicitudCredencial::query()
            ->select(['id', 'numero_tarjeta', 'numero_oficio', 'area_solicitante', 'estado_general'])
            ->withCount('elementos')
            ->where(fn (Builder $w) => $w->where('numero_tarjeta', 'like', $contiene)->orWhere('numero_oficio', 'like', $contiene))
            // Un ID numérico de 2 dígitos solo busca coincidencias exactas de ID; "contiene" sería demasiado amplio.
            ->when(mb_strlen($q) < 3, fn (Builder $w) => $w->whereRaw('1 = 0'))
            ->orderByDesc('consecutivo_tarjeta');
    }

    private function folios(string $q): Builder
    {
        return CredencialEmitida::query()
            ->select(['id', 'folio', 'estado_emision', 'solicitud_id', 'solicitud_elemento_id'])
            ->with(['solicitud:id,numero_tarjeta', 'solicitudElemento:id,cuip,persona_id', 'solicitudElemento.persona:id,nombre'])
            ->where(fn (Builder $w) => $w->where('folio', $q)->when(mb_strlen($q) >= 4, fn (Builder $p) => $p->orWhere('folio', 'like', $this->prefijo($q))))
            ->orderBy('folio');
    }

    private function vigencias(string $q): Builder
    {
        return LocVigenciaCredencial::query()
            ->select(['id', 'numero_credencial', 'estatus_credencial', 'periodo_credencial', 'cuip', 'persona_id', 'es_referencia_loc'])
            ->with('persona:id,nombre')
            ->where('numero_credencial', $q)
            ->orderBy('id');
    }

    /** Vínculo con el padrón con el mismo criterio que LocCredentialsPolicyService (regla 0). */
    public static function vinculoVerificado(LocVigenciaCredencial $vigencia): bool
    {
        return $vigencia->persona_id !== null && (bool) $vigencia->es_referencia_loc;
    }

    /** @return list<string> términos de nombre (solo si no hay dígitos; cada uno de 2+ letras) */
    public function terminosNombre(string $q): array
    {
        if (preg_match('/\d/', $q) || mb_strlen($q) < 3) {
            return [];
        }

        return array_values(array_filter(explode(' ', $q), static fn (string $t): bool => mb_strlen($t) >= 2));
    }

    private function prefijo(string $q): string
    {
        return addcslashes($q, '%_\\').'%';
    }

    private function paginar(Builder $consulta, string $grupo): LengthAwarePaginator
    {
        return $consulta->paginate(self::POR_PAGINA, ['*'], $grupo.'_page')->withQueryString();
    }
}
