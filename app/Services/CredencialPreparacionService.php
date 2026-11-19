<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BiometricoCategoria;
use App\Enums\BiometricoIncidenciaSeveridad;
use App\Enums\BiometricoIncidenciaTipo;
use App\Enums\EstadoEmision;
use App\Exceptions\EvidenciaBiometricaException;
use App\Models\BiometricoArchivoVersion;
use App\Models\BiometricoAuditoria;
use App\Models\BiometricoEvidencia;
use App\Models\BiometricoExpediente;
use App\Models\BiometricoIncidencia;
use App\Models\CredencialEmitida;
use App\Models\CredencialImpresion;
use App\Models\CredencialImpresionRectificacion;
use App\Models\CredencialPreparacion;
use App\Models\CredencialPreparacionBiometrico;
use App\Models\SolicitudElemento;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Preparación de impresión (Fase 1B.2): congela plantilla, datos variables y los cuatro biométricos
 * antes de imprimir, y registra la confirmación del resultado físico.
 *
 * Orden de bloqueo uniforme (Fase 1A, R5): elemento → credencial → expediente → preparaciones.
 * La copia de archivos ocurre fuera de la transacción SQL; dentro solo se registran referencias a
 * contenido ya publicado y verificado.
 */
class CredencialPreparacionService
{
    public const MANIFIESTO_VERSION = 1;

    /** Campo de la plantilla → categoría biométrica servida desde el snapshot. */
    public const CAMPOS_BIOMETRICOS = [
        'fotografia' => BiometricoCategoria::FOTO,
        'firma_titular' => BiometricoCategoria::FIRMA,
        'firma_persona' => BiometricoCategoria::FIRMA,
        'huella_derecha' => BiometricoCategoria::PULGAR_DERECHO,
        'huella_izquierda' => BiometricoCategoria::PULGAR_IZQUIERDO,
    ];

    private const RELACIONES = [
        'foto' => 'foto',
        'firma' => 'firma',
        'pulgar_derecho' => 'pulgarDerecho',
        'pulgar_izquierdo' => 'pulgarIzquierdo',
    ];

    public function __construct(
        private readonly BiometricEvidenceStore $almacen,
        private readonly CredencialDocumentoService $documento,
        private readonly BiometricDeliveryService $entrega,
    ) {}

    /**
     * Crea o recupera (idempotente) la preparación activa de una credencial.
     */
    public function preparar(SolicitudElemento $elemento, string $tipo, User $actor, ?string $versionPlantilla = null): CredencialPreparacion
    {
        if (! in_array($tipo, [CredencialPreparacion::TIPO_EMISION, CredencialPreparacion::TIPO_REIMPRESION_HISTORICA], true)) {
            throw new \InvalidArgumentException('Tipo de preparación no admitido.');
        }

        for ($intento = 1; $intento <= 2; $intento++) {
            // Validación previa (sin bloqueo) para no copiar archivos de una operación que se rechazará;
            // la transacción vuelve a validar con bloqueo.
            $elemento->refresh()->load('solicitud');
            $credencial = CredencialEmitida::query()->where('solicitud_elemento_id', $elemento->id)->first();
            $this->validarImprimible($elemento, $credencial);

            // La emisión se reutiliza solo con la misma configuración de plantilla (hash), no por número de versión.
            $plantilla = $tipo === CredencialPreparacion::TIPO_EMISION ? $this->plantillaParaEmision($versionPlantilla) : null;
            $activa = $this->activa($credencial->id);
            if ($activa !== null && $this->reutilizable($activa, $tipo, $plantilla['sha256'] ?? null)) {
                return $activa;
            }

            $uuid = (string) Str::uuid();
            $insumos = $tipo === CredencialPreparacion::TIPO_EMISION
                ? $this->insumosDeEmision($elemento, $credencial, $uuid, $plantilla)
                : $this->insumosDeReimpresion($credencial, $uuid);

            try {
                $resultado = DB::transaction(fn () => $this->registrarPreparacion($elemento, $tipo, $actor, $uuid, $insumos), 3);
            } catch (UniqueConstraintViolationException) {
                // Respaldo de la columna generada clave_activa: otra petición creó la preparación activa.
                $resultado = $this->activa($credencial->id);
            }

            if ($resultado instanceof CredencialPreparacion) {
                return $resultado;
            }
            // null: los archivos vigentes cambiaron durante la copia; se repite una sola vez.
        }

        throw new ConflictHttpException('La biometría del elemento cambió mientras se preparaba la impresión. Intente de nuevo.');
    }

    /**
     * Única regla de imprimibilidad (preparación, reutilización, confirmación y lanzador de la vista).
     */
    public function validarImprimible(SolicitudElemento $elemento, ?CredencialEmitida $credencial): void
    {
        if ($elemento->solicitud?->estado_general !== 'enviada_impresion') {
            throw new ConflictHttpException('La tarjeta de trabajo no está autorizada para impresión.');
        }
        if ($elemento->estado_tramite !== 'listo_impresion') {
            throw new ConflictHttpException('El elemento no está listo para impresión.');
        }
        if ($credencial === null || blank($credencial->folio)) {
            throw new ConflictHttpException('El elemento no tiene folio asignado.');
        }
    }

    /**
     * Categorías del snapshot que todavía no se han servido satisfactoriamente a la vista de impresión.
     * Solo cuentan entregas de la evidencia exacta de esta preparación.
     *
     * @return list<string>
     */
    public function evidenciasPendientes(CredencialPreparacion $preparacion): array
    {
        $entregadas = DB::table('credencial_preparacion_entregas as e')
            ->join('credencial_preparacion_biometricos as b', static function ($join): void {
                $join->on('b.credencial_preparacion_id', '=', 'e.credencial_preparacion_id')
                    ->on('b.categoria', '=', 'e.categoria')
                    ->on('b.biometrico_evidencia_id', '=', 'e.biometrico_evidencia_id');
            })
            ->where('e.credencial_preparacion_id', $preparacion->id)
            ->pluck('e.categoria')
            ->all();

        return array_values(array_diff(array_map(static fn (BiometricoCategoria $c): string => $c->value, BiometricoCategoria::requeridas()), $entregadas));
    }

    private function reutilizable(CredencialPreparacion $activa, string $tipo, ?string $shaPlantilla): bool
    {
        return $activa->estaPreparada()
            && $activa->tipo_operacion === $tipo
            && $activa->vistaVigente()
            && ($shaPlantilla === null || hash_equals((string) $activa->plantilla_config_sha256, $shaPlantilla));
    }

    /**
     * Plantilla publicada para una emisión, con sus bytes canónicos y su hash.
     * Exige que la plantilla muestre las cuatro evidencias biométricas: sin ellas la impresión
     * nunca podría confirmarse como correcta.
     *
     * @return array{config: array<string, mixed>, version: int, version_id: int|null, origen: string, bytes: string, sha256: string}
     */
    private function plantillaParaEmision(?string $versionPlantilla): array
    {
        $plantilla = $this->documento->plantillaPublicada($versionPlantilla);
        if (! $this->documento->tieneDimensionesDeTarjeta($plantilla['config'])) {
            throw new ConflictHttpException('La impresión requiere una versión aprobada en formato 54 × 86 mm.');
        }

        $mostradas = [];
        foreach ($plantilla['config']['faces'] ?? [] as $cara) {
            foreach ($cara['elements'] ?? [] as $elemento) {
                $categoria = self::CAMPOS_BIOMETRICOS[$elemento['field'] ?? ''] ?? null;
                if ($categoria !== null && ($elemento['visible'] ?? false)) {
                    $mostradas[$categoria->value] = true;
                }
            }
        }
        $faltantes = array_diff(array_map(static fn (BiometricoCategoria $c): string => $c->value, BiometricoCategoria::requeridas()), array_keys($mostradas));
        if ($faltantes !== []) {
            throw new ConflictHttpException('La plantilla publicada no muestra todas las evidencias biométricas ('.implode(', ', $faltantes).').');
        }

        $bytes = CanonicalJson::encode($plantilla['config']);

        return [...$plantilla, 'bytes' => $bytes, 'sha256' => CanonicalJson::hash($bytes)];
    }

    /**
     * Marca como expirada una preparación vencida (expiración perezosa; la conciliación hace lo mismo en lote).
     */
    public function expirarSiCorresponde(CredencialPreparacion $preparacion): CredencialPreparacion
    {
        if ($preparacion->estaPreparada() && now()->gte($preparacion->expira_en)) {
            $preparacion->forceFill(['estado' => CredencialPreparacion::EXPIRADA, 'expirada_at' => now()])->save();
        }

        return $preparacion;
    }

    public function expirarVencidas(): int
    {
        return CredencialPreparacion::query()
            ->where('estado', CredencialPreparacion::PREPARADA)
            ->where('expira_en', '<=', now())
            ->update(['estado' => CredencialPreparacion::EXPIRADA, 'expirada_at' => now(), 'updated_at' => now()]);
    }

    public function cancelar(CredencialPreparacion $preparacion, User $actor, string $motivo): CredencialPreparacion
    {
        return DB::transaction(function () use ($preparacion, $actor, $motivo): CredencialPreparacion {
            $bloqueada = CredencialPreparacion::query()->whereKey($preparacion->id)->lockForUpdate()->firstOrFail();
            if (! $bloqueada->estaPreparada()) {
                throw new ConflictHttpException('Solo una preparación vigente puede cancelarse.');
            }
            $bloqueada->forceFill([
                'estado' => CredencialPreparacion::CANCELADA,
                'cancelada_at' => now(),
                'cancelada_por_user_id' => $actor->id,
                'motivo_cancelacion' => Str::limit($motivo, 250, ''),
            ])->save();

            return $bloqueada;
        });
    }

    /**
     * Cancela las preparaciones vigentes de una persona (por CUIP) tras actualizar su biometría.
     * Se invoca dentro de la transacción de BiometricUpdateService, después de bloquear el expediente.
     * El snapshot, sus evidencias y la posibilidad de confirmación extemporánea se conservan.
     */
    public function cancelarActivasPorCuip(string $cuip, string $motivo, ?User $actor): int
    {
        $cuip = strtoupper(trim($cuip));
        $expedientes = BiometricoExpediente::query()->where('cuip', $cuip)->pluck('id');
        $elementos = SolicitudElemento::query()->where('cuip', $cuip)->pluck('id');

        return CredencialPreparacion::query()
            ->where('estado', CredencialPreparacion::PREPARADA)
            ->where(static function ($query) use ($expedientes, $elementos): void {
                $query->whereIn('biometrico_expediente_id', $expedientes)->orWhereIn('solicitud_elemento_id', $elementos);
            })
            ->update([
                'estado' => CredencialPreparacion::CANCELADA,
                'cancelada_at' => now(),
                'cancelada_por_user_id' => $actor?->id,
                'motivo_cancelacion' => $motivo,
                'updated_at' => now(),
            ]);
    }

    /**
     * Devuelve los bytes verificados de un componente del snapshot.
     * $historico=false: vista de impresión (exige vista vigente y registra la primera visualización).
     * $historico=true: consulta de evidencia histórica (sin vigencia; la autorización la exige el controlador).
     *
     * @return array{bytes: string, mime: string, formato: string}
     */
    public function biometrico(CredencialPreparacion $preparacion, BiometricoCategoria $categoria, User $actor, bool $historico): array
    {
        $this->expirarSiCorresponde($preparacion);
        if (! $historico && ! $preparacion->vistaVigente()) {
            throw new ConflictHttpException('La vista de esta preparación ya no está vigente; prepare nuevamente la impresión.');
        }

        /** @var CredencialPreparacionBiometrico|null $componente */
        $componente = $preparacion->biometricos()->where('categoria', $categoria->value)->with('evidencia')->first();
        if ($componente?->evidencia === null) {
            throw new ConflictHttpException('El componente biométrico no tiene evidencia preservada.');
        }

        try {
            $bytes = $this->almacen->leerVerificado($componente->evidencia);
        } catch (EvidenciaBiometricaException $e) {
            $this->registrarIncidencia($e, $preparacion, 'evidencia/'.$componente->evidencia->sha256, $categoria->value);
            throw new ConflictHttpException($e->getMessage());
        }

        if (! $historico) {
            // Entrega verificada de la evidencia exacta del snapshot (no la biometría vigente): una por categoría.
            // Es un control técnico de consistencia; no prueba que el operador viera la imagen ni que se imprimiera.
            DB::table('credencial_preparacion_entregas')->insertOrIgnore([
                'credencial_preparacion_id' => $preparacion->id,
                'categoria' => $categoria->value,
                'biometrico_evidencia_id' => $componente->evidencia->id,
                'sha256' => $componente->evidencia->sha256,
                'created_at' => now(),
            ]);
            if ($preparacion->primera_vista_at === null) {
                // Única escritura permitida en la vista: registra que el snapshot llegó a servirse.
                CredencialPreparacion::query()->whereKey($preparacion->id)->whereNull('primera_vista_at')
                    ->update(['primera_vista_at' => now(), 'updated_at' => now()]);
            }
        }

        BiometricoAuditoria::registrarAcceso(
            evento: $historico ? 'consulta_evidencia_historica' : 'streaming_evidencia_preparacion',
            actor: $actor,
            expediente: $preparacion->expediente,
            elemento: $preparacion->elemento,
            detalles: [
                'preparacion_uuid' => $preparacion->uuid,
                'categoria' => $categoria->value,
                'evidencia_id' => $componente->evidencia->id,
                'sha256' => $componente->evidencia->sha256,
            ],
        );

        return ['bytes' => $bytes, 'mime' => $componente->evidencia->mime_real, 'formato' => $componente->evidencia->formato];
    }

    /**
     * Datos variables descifrados y verificados contra su hash en claro.
     *
     * @return array<string, string|null>
     */
    public function datosVariables(CredencialPreparacion $preparacion): array
    {
        $claro = Crypt::decryptString((string) $preparacion->datos_variables_cifrados);
        if (CanonicalJson::hash($claro) !== $preparacion->datos_variables_sha256) {
            throw new ConflictHttpException('Los datos variables de la preparación no coinciden con su hash.');
        }

        return json_decode($claro, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function plantilla(CredencialPreparacion $preparacion): array
    {
        $bytes = (string) $preparacion->plantilla_config_canonica;
        if (CanonicalJson::hash($bytes) !== $preparacion->plantilla_config_sha256) {
            throw new ConflictHttpException('La plantilla de la preparación no coincide con su hash.');
        }

        return json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Confirma el resultado físico de una preparación (una sola vez). Normal si sigue vigente;
     * extemporánea (justificada, dentro de la ventana y con primera visualización) si fue cancelada o expiró.
     *
     * @param  array{resultado: string, impresora?: string|null, estacion_id?: int|null, motivo?: string|null, extemporanea?: bool, justificacion_extemporanea?: string|null}  $datos
     * @return array{estado_emision: string, numero_intento: int, folio: string, modalidad: string, idempotente: bool, posible_duplicado: bool}
     */
    public function confirmar(SolicitudElemento $elemento, string $uuid, bool $reimpresion, array $datos, User $actor): array
    {
        return DB::transaction(function () use ($elemento, $uuid, $reimpresion, $datos, $actor): array {
            $elementoLocked = SolicitudElemento::whereKey($elemento->id)->with('solicitud')->lockForUpdate()->firstOrFail();
            $credencial = CredencialEmitida::where('solicitud_elemento_id', $elementoLocked->id)->lockForUpdate()->first();

            $this->validarImprimible($elementoLocked, $credencial);

            $preparacion = CredencialPreparacion::query()->where('uuid', $uuid)->where('credencial_emitida_id', $credencial->id)->lockForUpdate()->first();
            abort_if($preparacion === null, 404, 'La preparación no corresponde a esta credencial.');
            $tipoEsperado = $reimpresion ? CredencialPreparacion::TIPO_REIMPRESION_HISTORICA : CredencialPreparacion::TIPO_EMISION;
            abort_unless($preparacion->tipo_operacion === $tipoEsperado, 409, 'La preparación no corresponde a este tipo de impresión.');

            $existente = CredencialImpresion::query()->where('credencial_preparacion_id', $preparacion->id)->first();
            if ($existente !== null) {
                abort_unless($existente->resultado === $datos['resultado'], 409, 'Esta preparación ya fue confirmada con otro resultado; registre una rectificación.');

                return $this->respuestaConfirmacion($credencial, $existente, true, false);
            }

            $this->expirarSiCorresponde($preparacion);
            $extemporanea = (bool) ($datos['extemporanea'] ?? false);
            if ($preparacion->estaPreparada()) {
                abort_if($extemporanea, 409, 'La preparación sigue vigente: registre la confirmación normal.');
                $modalidad = CredencialImpresion::MODALIDAD_NORMAL;
            } else {
                abort_unless($extemporanea, 409, "La preparación está {$preparacion->estado}: solo admite una confirmación extemporánea justificada.");
                abort_if($preparacion->primera_vista_at === null, 409, 'La preparación nunca llegó a mostrarse; no puede documentarse una impresión desde ella.');
                $limite = $preparacion->primera_vista_at->copy()->addHours((int) config('credencializacion.impresion.extemporanea_horas', 72));
                abort_if(now()->gt($limite), 409, 'La confirmación extemporánea está fuera de la ventana autorizada.');
                abort_if(blank($datos['justificacion_extemporanea'] ?? null), 422, 'La confirmación extemporánea exige justificación.');
                $modalidad = CredencialImpresion::MODALIDAD_EXTEMPORANEA;
            }

            // "Impresa" (normal o extemporánea) exige que las cuatro evidencias del snapshot se hayan servido;
            // el servidor lo comprueba con sus propios registros, sin confiar en el navegador.
            if ($datos['resultado'] === CredencialImpresion::RESULTADO_IMPRESA) {
                $pendientes = $this->evidenciasPendientes($preparacion);
                abort_if($pendientes !== [], 409, 'No es posible confirmar la impresión: no se sirvieron todas las evidencias biométricas de la preparación (faltan: '.implode(', ', $pendientes).').');
            }

            $posibleDuplicado = false;
            if ($reimpresion) {
                abort_unless($credencial->estaEmitida(), 409, 'Solo puede reimprimirse una credencial ya emitida.');
            } elseif ($credencial->estaEmitida()) {
                abort_if($modalidad === CredencialImpresion::MODALIDAD_NORMAL, 409, 'La credencial ya fue emitida; registre una reimpresión con motivo.');
                $posibleDuplicado = $datos['resultado'] === CredencialImpresion::RESULTADO_IMPRESA;
            }

            // reorder(): la relación ordena por numero_intento y un ORDER BY junto a MAX() puede fallar en MySQL.
            $numeroIntento = (int) $credencial->impresiones()->reorder()->max('numero_intento') + 1;
            $impresion = $credencial->impresiones()->create([
                'solicitud_elemento_id' => $elementoLocked->id,
                'credencial_preparacion_id' => $preparacion->id,
                'usuario_id' => $actor->id,
                'estacion_id' => $datos['estacion_id'] ?? null,
                'impresora' => $datos['impresora'] ?? null,
                'resultado' => $datos['resultado'],
                'modalidad_confirmacion' => $modalidad,
                'justificacion_extemporanea' => $modalidad === CredencialImpresion::MODALIDAD_EXTEMPORANEA ? $datos['justificacion_extemporanea'] : null,
                'motivo' => $datos['motivo'] ?? null,
                'numero_intento' => $numeroIntento,
            ]);

            if ($preparacion->estaPreparada()) {
                $preparacion->forceFill(['estado' => CredencialPreparacion::CONFIRMADA])->save();
            }

            if (! $reimpresion && ! $credencial->estaEmitida() && $datos['resultado'] === CredencialImpresion::RESULTADO_IMPRESA) {
                $credencial->forceFill([
                    'estado_emision' => EstadoEmision::EMITIDA->value,
                    'fecha_emision' => now(),
                    'emitida_por_user_id' => $actor->id,
                ])->save();
            }

            if ($posibleDuplicado) {
                // No se modifica la credencial ni se oculta: queda una incidencia pendiente de resolución autorizada.
                BiometricoIncidencia::create([
                    'credencial_preparacion_id' => $preparacion->id,
                    'tipo' => BiometricoIncidenciaTipo::CONFLICTO_IDENTIDAD,
                    'severidad' => BiometricoIncidenciaSeveridad::ALTA,
                    'codigo' => 'POSIBLE_TARJETA_DUPLICADA',
                    'ruta_relativa' => 'preparacion/'.$preparacion->uuid,
                    'nombre_archivo' => '-',
                    'descripcion' => 'Se confirmó extemporáneamente la impresión de una emisión cuando la credencial ya estaba emitida: posible segunda tarjeta física.',
                    'detalles' => ['credencial_emitida_id' => $credencial->id, 'credencial_impresion_id' => $impresion->id],
                ]);
            }

            return $this->respuestaConfirmacion($credencial, $impresion, false, $posibleDuplicado);
        });
    }

    /**
     * Rectificación auditable de un resultado confirmado por error. No reescribe el registro original
     * ni cambia por sí misma el estado de la credencial (requiere procedimiento institucional).
     */
    public function rectificar(CredencialImpresion $impresion, string $resultado, string $motivo, User $actor): CredencialImpresionRectificacion
    {
        return DB::transaction(function () use ($impresion, $resultado, $motivo, $actor): CredencialImpresionRectificacion {
            $bloqueada = CredencialImpresion::query()->whereKey($impresion->id)->lockForUpdate()->firstOrFail();
            $vigente = $bloqueada->resultadoVigente();
            if ($vigente === $resultado) {
                throw new ConflictHttpException('El resultado indicado ya es el resultado vigente de este registro.');
            }

            return CredencialImpresionRectificacion::create([
                'credencial_impresion_id' => $bloqueada->id,
                'resultado_anterior' => $vigente,
                'resultado_rectificado' => $resultado,
                'motivo' => $motivo,
                'usuario_id' => $actor->id,
            ]);
        });
    }

    public function resolverIncidencia(BiometricoIncidencia $incidencia, string $resolucion, User $actor): BiometricoIncidencia
    {
        return DB::transaction(function () use ($incidencia, $resolucion, $actor): BiometricoIncidencia {
            $bloqueada = BiometricoIncidencia::query()->whereKey($incidencia->id)->lockForUpdate()->firstOrFail();
            if ($bloqueada->credencial_preparacion_id === null) {
                throw new ConflictHttpException('Solo se resuelven aquí incidencias de evidencia o impresión.');
            }
            if ($bloqueada->resuelta_at !== null) {
                throw new ConflictHttpException('La incidencia ya fue resuelta.');
            }
            $bloqueada->forceFill(['resuelta_at' => now(), 'resuelta_por_user_id' => $actor->id, 'resolucion' => $resolucion])->save();

            return $bloqueada;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function huellaDeRenderizado(): array
    {
        $manifiestoVite = public_path('build/manifest.json');

        return [
            'app_release' => config('credencializacion.impresion.app_release'),
            'vista_sha256' => hash_file('sha256', resource_path('views/modules/credential-template-print.blade.php')),
            'vite_manifest_sha256' => is_file($manifiestoVite) ? hash_file('sha256', $manifiestoVite) : null,
        ];
    }

    private function activa(int $credencialId): ?CredencialPreparacion
    {
        $activa = CredencialPreparacion::query()->where('credencial_emitida_id', $credencialId)->where('estado', CredencialPreparacion::PREPARADA)->first();

        return $activa ? $this->expirarSiCorresponde($activa)->refresh() : null;
    }

    /**
     * Valida 4/4, copia los cuatro archivos vigentes al almacén y construye el snapshot documental.
     *
     * @return array<string, mixed>
     */
    private function insumosDeEmision(SolicitudElemento $elemento, CredencialEmitida $credencial, string $uuid, array $plantilla): array
    {
        if ($credencial->estaEmitida()) {
            throw new ConflictHttpException('La credencial ya fue emitida; prepare una reimpresión.');
        }

        $elemento->loadMissing(['solicitud', 'persona.vigencias', 'armaCorta', 'armaLarga', 'credencialEmitida', 'expedienteBiometrico']);
        $expediente = $elemento->expedienteBiometrico;
        if ($expediente === null) {
            throw new ConflictHttpException('El elemento no tiene expediente biométrico: se requieren los cuatro biométricos.');
        }
        $expediente->load(array_values(self::RELACIONES));

        $componentes = [];
        foreach (BiometricoCategoria::requeridas() as $categoria) {
            $archivo = $expediente->{self::RELACIONES[$categoria->value]};
            $evaluacion = $expediente->evaluarArchivoTecnico($archivo, $categoria);
            if (! $evaluacion['valido']) {
                throw new ConflictHttpException("No es posible preparar la impresión: biométrico no válido ({$evaluacion['motivo']}).");
            }

            $etiqueta = $uuid.'-'.$categoria->value;
            try {
                $disco = $this->entrega->discoDeArchivo($archivo);
                $flujo = $disco->readStream($archivo->ruta_relativa);
                if (! is_resource($flujo)) {
                    throw new EvidenciaBiometricaException('EVIDENCIA_ORIGEN_ILEGIBLE', 'No fue posible leer el archivo biométrico de origen.', 'alta');
                }
                try {
                    $publicacion = $this->almacen->publicar($flujo, (string) $archivo->sha256, $archivo->tamano_bytes ?: null, $etiqueta);
                } finally {
                    fclose($flujo);
                }
            } catch (EvidenciaBiometricaException $e) {
                $this->registrarIncidencia($e, null, 'preparacion/'.$uuid, $categoria->value);
                throw new ConflictHttpException($e->getMessage());
            } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                $this->registrarIncidencia(new EvidenciaBiometricaException('EVIDENCIA_ORIGEN_FALTANTE', 'El archivo biométrico vigente no existe físicamente.', 'critica'), null, 'preparacion/'.$uuid, $categoria->value);
                throw new ConflictHttpException('El archivo biométrico vigente no existe físicamente.');
            }

            $version = $archivo->version_secuencia > 1
                ? BiometricoArchivoVersion::query()->where('biometrico_archivo_id', $archivo->id)
                    ->where('version_secuencia', $archivo->version_secuencia)->where('sha256_nuevo', $archivo->sha256)->value('id')
                : null;

            $componentes[$categoria->value] = [
                'publicacion' => $publicacion,
                'archivo_id' => $archivo->id,
                'archivo_sha256' => $archivo->sha256,
                'version_id' => $version !== null ? (int) $version : null,
            ];
        }

        $config = $plantilla['config'];
        $campos = array_flip($this->documento->camposRenderizados($config));
        $documento = $this->documento->datosVariables($elemento);
        $datos = array_intersect_key($documento['datos'], $campos);
        $porDefecto = array_values(array_intersect($documento['por_defecto'], array_keys($datos)));

        $plantillaBytes = $plantilla['bytes']; // los mismos bytes cuyo hash decidió la reutilización
        $datosBytes = CanonicalJson::encode($datos);
        $manifiesto = CanonicalJson::encode([
            'manifiesto_version' => self::MANIFIESTO_VERSION,
            'plantilla_config_sha256' => CanonicalJson::hash($plantillaBytes),
            'datos_variables_sha256' => CanonicalJson::hash($datosBytes),
            'biometricos' => array_map(static fn (array $c): string => $c['publicacion']['sha256'], $componentes),
        ]);

        return [
            'expediente_id' => $expediente->id,
            'componentes' => $componentes,
            'origen_id' => null,
            'plantilla_version_id' => $plantilla['version_id'],
            'plantilla_origen' => $plantilla['origen'],
            'plantilla_version' => $plantilla['version'],
            'plantilla_config_canonica' => $plantillaBytes,
            'datos_variables_cifrados' => Crypt::encryptString($datosBytes),
            'datos_variables_sha256' => CanonicalJson::hash($datosBytes),
            'campos_por_defecto' => $porDefecto,
            'manifiesto_canonico' => $manifiesto,
        ];
    }

    /**
     * Reimpresión histórica: reutiliza exactamente los insumos de la preparación de la emisión original
     * (mismas evidencias, mismos bytes canónicos, mismo cifrado); nunca lee los biométricos vigentes.
     *
     * @return array<string, mixed>
     */
    private function insumosDeReimpresion(CredencialEmitida $credencial, string $uuid): array
    {
        if (! $credencial->estaEmitida()) {
            throw new ConflictHttpException('Solo puede reimprimirse una credencial ya emitida.');
        }

        $origen = CredencialPreparacion::query()
            ->where('credencial_emitida_id', $credencial->id)
            ->where('tipo_operacion', CredencialPreparacion::TIPO_EMISION)
            ->whereHas('impresion', static fn ($q) => $q->where('resultado', CredencialImpresion::RESULTADO_IMPRESA))
            ->with('biometricos.evidencia')
            ->orderBy('id')
            ->first();
        if ($origen === null) {
            throw new ConflictHttpException('La credencial no tiene evidencia histórica de su emisión (emitida antes de conservar snapshots); la reimpresión histórica no es posible.');
        }

        // Verificación de los insumos originales antes de reutilizarlos.
        $this->plantilla($origen);
        $this->datosVariables($origen);
        if ($origen->biometricos->count() !== 4) {
            throw new ConflictHttpException('La emisión original no conserva los cuatro biométricos.');
        }
        foreach ($origen->biometricos as $componente) {
            if ($componente->evidencia === null || $componente->clasificacion !== CredencialPreparacionBiometrico::VERIFICADA) {
                throw new ConflictHttpException('La emisión original no conserva evidencia verificada de todos los biométricos.');
            }
            try {
                $this->almacen->leerVerificado($componente->evidencia);
            } catch (EvidenciaBiometricaException $e) {
                $this->registrarIncidencia($e, $origen, 'evidencia/'.$componente->evidencia->sha256, $componente->categoria->value);
                throw new ConflictHttpException($e->getMessage());
            }
        }

        return [
            'expediente_id' => $origen->biometrico_expediente_id,
            'componentes' => null,
            'origen' => $origen,
            'origen_id' => $origen->id,
            'plantilla_version_id' => $origen->credential_template_version_id,
            'plantilla_origen' => $origen->plantilla_origen,
            'plantilla_version' => $origen->plantilla_version,
            'plantilla_config_canonica' => $origen->plantilla_config_canonica,
            'datos_variables_cifrados' => $origen->datos_variables_cifrados,
            'datos_variables_sha256' => $origen->datos_variables_sha256,
            'campos_por_defecto' => $origen->campos_por_defecto,
            'manifiesto_canonico' => $origen->manifiesto_canonico,
        ];
    }

    /**
     * Transacción: bloqueos en orden uniforme, revalidación y registro. Devuelve null si los archivos
     * vigentes cambiaron desde la copia (el llamador reintenta una vez).
     *
     * @param  array<string, mixed>  $insumos
     */
    private function registrarPreparacion(SolicitudElemento $elemento, string $tipo, User $actor, string $uuid, array $insumos): ?CredencialPreparacion
    {
        $elementoLocked = SolicitudElemento::query()->whereKey($elemento->id)->with('solicitud')->lockForUpdate()->firstOrFail();
        $credencial = CredencialEmitida::query()->where('solicitud_elemento_id', $elementoLocked->id)->lockForUpdate()->firstOrFail();
        $expediente = $insumos['expediente_id'] !== null
            ? BiometricoExpediente::query()->whereKey($insumos['expediente_id'])->lockForUpdate()->first()
            : null;

        $this->validarImprimible($elementoLocked, $credencial);

        if ($tipo === CredencialPreparacion::TIPO_EMISION) {
            abort_if($credencial->estaEmitida(), 409, 'La credencial ya fue emitida; prepare una reimpresión.');
            foreach ($insumos['componentes'] as $categoria => $componente) {
                $columna = BiometricoCategoria::from($categoria)->columnaExpediente();
                $vigente = $expediente?->getAttribute($columna);
                $sha = $vigente ? \App\Models\BiometricoArchivo::query()->whereKey($vigente)->value('sha256') : null;
                if ((int) $vigente !== $componente['archivo_id'] || $sha !== $componente['archivo_sha256']) {
                    return null;
                }
            }
        } else {
            abort_unless($credencial->estaEmitida(), 409, 'Solo puede reimprimirse una credencial ya emitida.');
        }

        $activa = CredencialPreparacion::query()->where('credencial_emitida_id', $credencial->id)
            ->where('estado', CredencialPreparacion::PREPARADA)->lockForUpdate()->first();
        if ($activa !== null) {
            $this->expirarSiCorresponde($activa);
            $shaSolicitado = CanonicalJson::hash($insumos['plantilla_config_canonica']);
            if ($this->reutilizable($activa, $tipo, $shaSolicitado)) {
                return $activa; // otra petición equivalente la creó mientras se copiaban archivos
            }
            if ($activa->estaPreparada()) {
                // La anterior conserva su snapshot y sus evidencias, pero ya no sirve imágenes ni admite confirmación normal.
                $cambioDePlantilla = $activa->tipo_operacion === $tipo && ! hash_equals((string) $activa->plantilla_config_sha256, $shaSolicitado);
                $activa->forceFill([
                    'estado' => CredencialPreparacion::CANCELADA,
                    'cancelada_at' => now(),
                    'cancelada_por_user_id' => $actor->id,
                    'motivo_cancelacion' => $cambioDePlantilla ? 'reemplazada_por_cambio_de_plantilla' : 'reemplazada_por_nueva_preparacion',
                ])->save();
            }
        }

        $ahora = now();
        $preparacion = CredencialPreparacion::create([
            'uuid' => $uuid,
            'credencial_emitida_id' => $credencial->id,
            'solicitud_elemento_id' => $elementoLocked->id,
            'biometrico_expediente_id' => $insumos['expediente_id'],
            'tipo_operacion' => $tipo,
            'preparacion_origen_id' => $insumos['origen_id'],
            'estado' => CredencialPreparacion::PREPARADA,
            'origen' => 'nueva',
            'estado_evidencia' => 'completa',
            'credential_template_version_id' => $insumos['plantilla_version_id'],
            'plantilla_origen' => $insumos['plantilla_origen'],
            'plantilla_version' => $insumos['plantilla_version'],
            'plantilla_config_canonica' => $insumos['plantilla_config_canonica'],
            'plantilla_config_sha256' => CanonicalJson::hash($insumos['plantilla_config_canonica']),
            'datos_variables_cifrados' => $insumos['datos_variables_cifrados'],
            'datos_variables_sha256' => $insumos['datos_variables_sha256'],
            'campos_por_defecto' => $insumos['campos_por_defecto'],
            'manifiesto_version' => self::MANIFIESTO_VERSION,
            'manifiesto_canonico' => $insumos['manifiesto_canonico'],
            'manifiesto_sha256' => CanonicalJson::hash($insumos['manifiesto_canonico']),
            'render_huella' => $this->huellaDeRenderizado(),
            'preparada_por_user_id' => $actor->id,
            'preparada_at' => $ahora,
            'vista_expira_en' => $ahora->copy()->addMinutes((int) config('credencializacion.impresion.vista_minutos', 30)),
            'expira_en' => $ahora->copy()->addHours((int) config('credencializacion.impresion.preparacion_horas', 24)),
        ]);

        if ($tipo === CredencialPreparacion::TIPO_EMISION) {
            foreach ($insumos['componentes'] as $categoria => $componente) {
                $evidencia = $this->almacen->registrar($componente['publicacion']);
                CredencialPreparacionBiometrico::create([
                    'credencial_preparacion_id' => $preparacion->id,
                    'categoria' => $categoria,
                    'biometrico_evidencia_id' => $evidencia->id,
                    'sha256' => $evidencia->sha256,
                    'biometrico_archivo_id' => $componente['archivo_id'],
                    'biometrico_archivo_version_id' => $componente['version_id'],
                    'clasificacion' => CredencialPreparacionBiometrico::VERIFICADA,
                ]);
            }
        } else {
            foreach ($insumos['origen']->biometricos as $componente) {
                CredencialPreparacionBiometrico::create([
                    'credencial_preparacion_id' => $preparacion->id,
                    'categoria' => $componente->categoria,
                    'biometrico_evidencia_id' => $componente->biometrico_evidencia_id,
                    'sha256' => $componente->sha256,
                    'biometrico_archivo_id' => $componente->biometrico_archivo_id,
                    'biometrico_archivo_version_id' => $componente->biometrico_archivo_version_id,
                    'clasificacion' => CredencialPreparacionBiometrico::VERIFICADA,
                ]);
            }
        }

        return $preparacion;
    }

    /**
     * @return array{estado_emision: string, numero_intento: int, folio: string, modalidad: string, idempotente: bool, posible_duplicado: bool}
     */
    private function respuestaConfirmacion(CredencialEmitida $credencial, CredencialImpresion $impresion, bool $idempotente, bool $posibleDuplicado): array
    {
        return [
            'estado_emision' => (string) $credencial->refresh()->estado_emision,
            'numero_intento' => (int) $impresion->numero_intento,
            'folio' => (string) $credencial->folio,
            'modalidad' => (string) $impresion->modalidad_confirmacion,
            'idempotente' => $idempotente,
            'posible_duplicado' => $posibleDuplicado,
        ];
    }

    /**
     * Incidencia de evidencia sin datos personales: ruta lógica (preparacion/<uuid> o evidencia/<sha>), nunca INGESTA.
     */
    private function registrarIncidencia(EvidenciaBiometricaException $e, ?CredencialPreparacion $preparacion, string $rutaLogica, ?string $categoria): void
    {
        try {
            BiometricoIncidencia::create([
                'credencial_preparacion_id' => $preparacion?->id,
                'tipo' => str_contains($e->codigo, 'FALTANTE') ? BiometricoIncidenciaTipo::ARCHIVO_DESAPARECIDO : BiometricoIncidenciaTipo::ERROR_PROCESAMIENTO,
                'severidad' => BiometricoIncidenciaSeveridad::tryFrom($e->severidad) ?? BiometricoIncidenciaSeveridad::ALTA,
                'codigo' => $e->codigo,
                'categoria' => $categoria,
                'ruta_relativa' => $rutaLogica,
                'nombre_archivo' => $categoria ?? '-',
                'descripcion' => $e->getMessage(),
                'detalles' => $e->detalles ?: null,
            ]);
        } catch (\Throwable) {
            // Registrar la incidencia nunca debe ocultar la falla original ni exponer datos en el error.
        }
    }
}
