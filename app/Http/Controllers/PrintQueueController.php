<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\BiometricoAmbito;
use App\Enums\BiometricoCategoria;
use App\Http\Requests\UpdateBiometricoElementoRequest;
use App\Models\BiometricoArchivo;
use App\Models\BiometricoAuditoria;
use App\Models\BiometricoExpediente;
use App\Models\CredencialEmitida;
use App\Models\CredentialTemplate;
use App\Models\LocCatalogoVersion;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use App\Services\BiometricDeliveryService;
use App\Services\BiometricResolverService;
use App\Services\BiometricUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PrintQueueController extends Controller
{
    /**
     * Muestra la interfaz principal de la cola de impresión.
     */
    public function index(Request $request): View
    {
        $section = $request->query('section', 'cola');

        return view('modules.impresion', [
            'section' => in_array($section, ['cola', 'biometricos'], true) ? $section : 'cola',
        ]);
    }

    /**
     * Retorna los elementos aptos administrativamente de una tarjeta de trabajo
     * junto con sus métricas para poblar la vista reactiva.
     */
    public function elementosPorTarjeta(Request $request, SolicitudCredencial $solicitud): JsonResponse
    {
        $elementos = $solicitud->elementos()
            ->where('resultado_prefiltro', 'Apto')
            ->with([
                'persona',
                'armaCorta',
                'armaLarga',
                'credencialEmitida',
                'expedienteBiometrico.foto',
                'expedienteBiometrico.firma',
                'expedienteBiometrico.pulgarDerecho',
                'expedienteBiometrico.pulgarIzquierdo',
            ])
            ->orderBy('numero_fila')
            ->orderBy('id')
            ->get();

        $totalAptos = $elementos->count();
        $totalListos = $elementos->where('estado_tramite', 'listo_impresion')->count();
        $totalPendientes = $elementos->where('estado_tramite', '!=', 'listo_impresion')->count();
        $totalImpresos = $elementos->filter(fn (SolicitudElemento $e) => $e->credencialEmitida?->estado_emision === 'emitida')->count();

        $data = $elementos->map(function (SolicitudElemento $elem): array {
            $nombreOficial = $elem->persona?->nombre;
            $nombreSolicitado = $elem->datos_solicitados['nombre'] ?? ($elem->datos_originales_fila['NOMBRE'] ?? null);
            $nombre = $nombreOficial ?: ($nombreSolicitado ?: 'SIN NOMBRE REGISTRADO');

            $credencial = $elem->credencialEmitida;
            $expediente = $elem->expedienteBiometrico;

            return [
                'id' => (int) $elem->id,
                'numero_fila' => $elem->numero_fila,
                'cuip' => $elem->cuip,
                'nombre' => (string) $nombre,
                'categoria' => $elem->persona?->categoria ?? ($elem->datos_solicitados['categoria'] ?? null),
                'municipio' => $elem->persona?->municipio ?? ($elem->datos_solicitados['municipio'] ?? null),
                'ambito_biometrico' => $elem->ambito_biometrico?->value,
                'estado_clasificacion_ambito' => $elem->estado_clasificacion_ambito?->value,
                'estado_tramite' => (string) $elem->estado_tramite,
                'biometrico_expediente_id' => $elem->biometrico_expediente_id,
                'tiene_expediente' => $elem->biometrico_expediente_id !== null,
                'es_completo' => (bool) ($expediente?->es_completo ?? false),
                'folio' => $credencial?->folio,
                'estado_emision' => $credencial?->estado_emision,
                'puede_imprimir' => $elem->estado_tramite === 'listo_impresion' && ! empty($credencial?->folio),
            ];
        });

        return response()->json([
            'success' => true,
            'solicitud_id' => $solicitud->id,
            'numero_tarjeta' => $solicitud->numero_tarjeta,
            'numero_oficio' => $solicitud->numero_oficio,
            'metricas' => [
                'trabajos_en_cola' => $totalAptos,
                'listos_para_imprimir' => $totalListos,
                'biometria_pendiente' => $totalPendientes,
                'impresos' => $totalImpresos,
            ],
            'elementos' => $data,
        ]);
    }

    /**
     * Núcleo del Negocio: Valida la biometría 4/4 para los elementos aptos de una tarjeta
     * de trabajo, orquestando BiometricResolverService y asignando folios oficiales en
     * caso de aptitud biométrica total.
     */
    public function validarBiometriaPorTarjeta(
        Request $request,
        SolicitudCredencial $solicitud,
        BiometricResolverService $resolverService
    ): JsonResponse {
        $user = $request->user();

        abort_unless(
            $user && (
                in_array($user->role, ['superadmin', 'administrador', 'impresion', 'operador', 'local_demo'], true) ||
                ($solicitud->usuario_responsable_id !== null && (int) $solicitud->usuario_responsable_id === (int) $user->id) ||
                app()->environment('local', 'testing')
            ),
            403,
            'No cuenta con autorización para validar e imprimir credenciales en esta tarjeta de trabajo.'
        );

        $elementoIds = $request->input('elementos', []);

        try {
            $resultado = DB::transaction(function () use ($solicitud, $elementoIds, $resolverService, $user): array {
                // Bloqueo pesimista de la solicitud
                /** @var SolicitudCredencial $solicitudLocked */
                $solicitudLocked = SolicitudCredencial::whereKey($solicitud->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Filtrar exclusivamente elementos administrativamente dictaminados como Apto
                $elementosQuery = $solicitudLocked->elementos()
                    ->where('resultado_prefiltro', 'Apto')
                    ->lockForUpdate();

                if (is_array($elementoIds) && ! empty($elementoIds)) {
                    $elementosQuery->whereIn('id', $elementoIds);
                }

                $elementos = $elementosQuery
                    ->with(['persona', 'credencialEmitida'])
                    ->orderBy('numero_fila')
                    ->orderBy('id')
                    ->get();

                if ($elementos->isEmpty()) {
                    return [
                        'success' => true,
                        'tarjeta' => $solicitudLocked->numero_tarjeta,
                        'message' => 'La solicitud no cuenta con elementos dictaminados como Apto para validar.',
                        'metricas' => [
                            'trabajos_en_cola' => 0,
                            'listos_para_imprimir' => 0,
                            'biometria_pendiente' => 0,
                            'impresos' => 0,
                        ],
                        'detalles' => [],
                    ];
                }

                // Fase A: Resolución de Ámbito para elementos pendientes de clasificación
                $catalogoVigente = LocCatalogoVersion::where('es_vigente', true)->first();
                foreach ($elementos as $elem) {
                    if (! $elem->isAmbitoResuelto()) {
                        $resolverService->resolverAmbitoParaElemento($elem, $catalogoVigente);
                        $elem->save();
                    }
                }

                // Fase B: Precarga Biométrica en Lote por (CUIP + Ámbito)
                $resolverService->resolveBatch($elementos);

                // Precargar relaciones de archivos en expedientes encontrados para evitar N+1
                $expedientesPrecargados = new \Illuminate\Database\Eloquent\Collection(
                    $elementos
                        ->map(fn (SolicitudElemento $e) => $e->relationLoaded('expedienteBiometrico') ? $e->expedienteBiometrico : null)
                        ->filter()
                        ->all()
                );

                if ($expedientesPrecargados->isNotEmpty()) {
                    $expedientesPrecargados->loadMissing(['foto', 'firma', 'pulgarDerecho', 'pulgarIzquierdo']);
                }

                $detalles = [];
                $totalListos = 0;
                $totalBloqueados = 0;

                // Fase C y D: Evaluación de Archivos 4/4 y Asignación de Folios
                foreach ($elementos as $elem) {
                    $cuip = strtoupper(trim((string) $elem->cuip));
                    $nombreOficial = $elem->persona?->nombre;
                    $nombreSolicitado = $elem->datos_solicitados['nombre'] ?? ($elem->datos_originales_fila['NOMBRE'] ?? null);
                    $nombre = $nombreOficial ?: ($nombreSolicitado ?: 'SIN NOMBRE REGISTRADO');

                    // C1: Comprobar si el ámbito está formalmente resuelto
                    if (! $elem->isAmbitoResuelto() || ! $elem->estado_clasificacion_ambito?->permiteAsociacionDefinitiva()) {
                        $elem->estado_tramite = 'pendiente_biometrico';
                        $elem->save();
                        $totalBloqueados++;

                        $detalles[] = [
                            'elemento_id' => (int) $elem->id,
                            'numero_fila' => $elem->numero_fila,
                            'cuip' => $cuip,
                            'nombre' => $nombre,
                            'ambito' => $elem->ambito_biometrico?->value ?? 'NO_RESUELTO',
                            'biometria_valida' => false,
                            'estado_tramite' => 'pendiente_biometrico',
                            'folio' => null,
                            'archivos' => [
                                'foto' => false,
                                'firma' => false,
                                'pulgar_derecho' => false,
                                'pulgar_izquierdo' => false,
                            ],
                            'faltantes' => ['ambito_no_resuelto'],
                            'mensaje' => 'Ámbito institucional no resuelto (pendiente de adscripción oficial).',
                        ];
                        continue;
                    }

                    // C2: Búsqueda del expediente biométrico estricto por (CUIP + Ámbito)
                    /** @var BiometricoExpediente|null $expediente */
                    $expediente = $elem->relationLoaded('expedienteBiometrico') ? $elem->expedienteBiometrico : null;

                    if ($expediente === null && $elem->biometrico_expediente_id !== null) {
                        $expediente = BiometricoExpediente::find($elem->biometrico_expediente_id);
                    }

                    if ($expediente === null) {
                        $expediente = BiometricoExpediente::query()
                            ->where('cuip', $cuip)
                            ->where('ambito', $elem->ambito_biometrico->value)
                            ->lockForUpdate()
                            ->first();
                    }

                    if (! $expediente) {
                        $elem->estado_tramite = 'pendiente_biometrico';
                        $elem->save();
                        $totalBloqueados++;

                        $detalles[] = [
                            'elemento_id' => (int) $elem->id,
                            'numero_fila' => $elem->numero_fila,
                            'cuip' => $cuip,
                            'nombre' => $nombre,
                            'ambito' => $elem->ambito_biometrico->value,
                            'biometria_valida' => false,
                            'estado_tramite' => 'pendiente_biometrico',
                            'folio' => null,
                            'archivos' => [
                                'foto' => false,
                                'firma' => false,
                                'pulgar_derecho' => false,
                                'pulgar_izquierdo' => false,
                            ],
                            'faltantes' => ['expediente_no_localizado'],
                            'mensaje' => 'Expediente biométrico no localizado para este CUIP y Ámbito.',
                        ];
                        continue;
                    }

                    // C3: Evaluación pericial de los 4 archivos
                    $expediente->loadMissing(['foto', 'firma', 'pulgarDerecho', 'pulgarIzquierdo']);

                    $evalFoto = $expediente->evaluarArchivoTecnico($expediente->foto, BiometricoCategoria::FOTO);
                    $evalFirma = $expediente->evaluarArchivoTecnico($expediente->firma, BiometricoCategoria::FIRMA);
                    $evalPulgarDer = $expediente->evaluarArchivoTecnico($expediente->pulgarDerecho, BiometricoCategoria::PULGAR_DERECHO);
                    $evalPulgarIzq = $expediente->evaluarArchivoTecnico($expediente->pulgarIzquierdo, BiometricoCategoria::PULGAR_IZQUIERDO);

                    $faltantes = [];
                    if (! $evalFoto['valido']) {
                        $faltantes[] = 'foto: ' . ($evalFoto['motivo'] ?? 'archivo no válido o no vigente');
                    }
                    if (! $evalFirma['valido']) {
                        $faltantes[] = 'firma: ' . ($evalFirma['motivo'] ?? 'archivo no válido o no vigente');
                    }
                    if (! $evalPulgarDer['valido']) {
                        $faltantes[] = 'pulgar_derecho: ' . ($evalPulgarDer['motivo'] ?? 'archivo no válido o no vigente');
                    }
                    if (! $evalPulgarIzq['valido']) {
                        $faltantes[] = 'pulgar_izquierdo: ' . ($evalPulgarIzq['motivo'] ?? 'archivo no válido o no vigente');
                    }

                    if (! empty($faltantes)) {
                        $elem->estado_tramite = 'pendiente_biometrico';
                        $elem->save();
                        $totalBloqueados++;

                        $detalles[] = [
                            'elemento_id' => (int) $elem->id,
                            'numero_fila' => $elem->numero_fila,
                            'cuip' => $cuip,
                            'nombre' => $nombre,
                            'ambito' => $elem->ambito_biometrico->value,
                            'biometria_valida' => false,
                            'estado_tramite' => 'pendiente_biometrico',
                            'folio' => null,
                            'archivos' => [
                                'foto' => (bool) $evalFoto['valido'],
                                'firma' => (bool) $evalFirma['valido'],
                                'pulgar_derecho' => (bool) $evalPulgarDer['valido'],
                                'pulgar_izquierdo' => (bool) $evalPulgarIzq['valido'],
                            ],
                            'faltantes' => $faltantes,
                            'mensaje' => 'Biometría incompleta o con archivos no vigentes.',
                        ];
                        continue;
                    }

                    // D1: Si no estaba asociado, formalizar asociación con BiometricResolverService
                    if ($elem->biometrico_expediente_id !== $expediente->id) {
                        $resolverService->asociarExpedienteAElemento($elem, $expediente, $user?->id);
                    }

                    // D2: Generación atómica de Folio y Registro de Emisión
                    $credencial = $elem->credencialEmitida;
                    if (! $credencial) {
                        $folio = $this->generarFolio($elem, $solicitudLocked);

                        $credencial = CredencialEmitida::create([
                            'solicitud_id' => $solicitudLocked->id,
                            'solicitud_elemento_id' => $elem->id,
                            'folio' => $folio,
                            'numero_tarjeta' => $solicitudLocked->numero_tarjeta,
                            'estado_biometria' => 'completo',
                            'estado_emision' => 'pendiente',
                            'estado_entrega' => 'pendiente',
                            'fecha_emision' => null,
                            'emitida_por_user_id' => $user?->id,
                            'observaciones' => 'Validación biométrica 4/4 exitosa en cola de impresión.',
                        ]);
                    } else {
                        $credencial->estado_biometria = 'completo';
                        $credencial->save();
                    }

                    // D3: Marcar elemento como listo para imprimir
                    $elem->estado_tramite = 'listo_impresion';
                    $elem->save();
                    $totalListos++;

                    $detalles[] = [
                        'elemento_id' => (int) $elem->id,
                        'numero_fila' => $elem->numero_fila,
                        'cuip' => $cuip,
                        'nombre' => $nombre,
                        'ambito' => $elem->ambito_biometrico->value,
                        'biometria_valida' => true,
                        'estado_tramite' => 'listo_impresion',
                        'folio' => $credencial->folio,
                        'archivos' => [
                            'foto' => true,
                            'firma' => true,
                            'pulgar_derecho' => true,
                            'pulgar_izquierdo' => true,
                        ],
                        'faltantes' => [],
                        'mensaje' => 'Biometría 100% válida. Folio asignado exitosamente.',
                    ];
                }

                $totalImpresos = $elementos->filter(fn (SolicitudElemento $e) => $e->credencialEmitida?->estado_emision === 'emitida')->count();

                return [
                    'success' => true,
                    'tarjeta' => $solicitudLocked->numero_tarjeta,
                    'metricas' => [
                        'trabajos_en_cola' => $elementos->count(),
                        'listos_para_imprimir' => $totalListos,
                        'biometria_pendiente' => $totalBloqueados,
                        'impresos' => $totalImpresos,
                    ],
                    'detalles' => $detalles,
                ];
            });

            return response()->json($resultado);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error durante la validación biométrica: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Genera de forma atómica y determinista el folio institucional consecutivo.
     */
    protected function generarFolio(SolicitudElemento $elemento, SolicitudCredencial $solicitud): string
    {
        $anio = date('Y');

        // 1. Consecutivo atómico desde la tabla de secuencias si existe
        $secuencia = DB::table('solicitud_secuencias')
            ->where('clave', 'folio_credencial')
            ->lockForUpdate()
            ->first();

        if ($secuencia !== null) {
            $siguiente = ((int) $secuencia->ultimo_consecutivo) + 1;
            DB::table('solicitud_secuencias')
                ->where('clave', 'folio_credencial')
                ->update([
                    'ultimo_consecutivo' => $siguiente,
                    'updated_at' => now(),
                ]);

            return sprintf('FOL-%s-%05d', $anio, $siguiente);
        }

        // 2. Fallback determinista basado en el ID de emisión y elemento
        $ultimoId = (int) DB::table('credenciales_emitidas')->max('id');
        $consecutivo = max($ultimoId + 1, (int) $elemento->id);

        $folio = sprintf('FOL-%s-%05d', $anio, $consecutivo);

        // Prevenir colisiones eventuales de clave única
        $intentos = 0;
        while (CredencialEmitida::where('folio', $folio)->exists() && $intentos < 50) {
            $consecutivo++;
            $folio = sprintf('FOL-%s-%05d', $anio, $consecutivo);
            $intentos++;
        }

        return $folio;
    }

    /**
     * Retorna el payload estructurado con los datos de plantilla, campos dinámicos
     * y URLs de streaming biométrico para el modal de vista previa interactivo.
     */
    public function previsualizacionElemento(
        Request $request,
        SolicitudElemento $elemento,
        BiometricResolverService $resolverService
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user && $user->can('viewPreview', $elemento), 403, 'No cuenta con autorización para visualizar los datos biométricos de este elemento.');

        $elemento->loadMissing([
            'solicitud',
            'persona.vigencias',
            'armaCorta',
            'armaLarga',
            'credencialEmitida',
            'expedienteBiometrico.foto',
            'expedienteBiometrico.firma',
            'expedienteBiometrico.pulgarDerecho',
            'expedienteBiometrico.pulgarIzquierdo',
        ]);

        if (! $elemento->isAmbitoResuelto()) {
            $resolverService->resolverAmbitoParaElemento($elemento);
            $elemento->save();
        }

        $requestedVersion = (string) $request->input('template_version', $request->input('template', ''));
        if (in_array($requestedVersion, ['2', 'v2', 'version_2'], true)) {
            $templateV2 = CredentialTemplate::query()
                ->where('slug', CredentialTemplateController::SLUG_V2)
                ->first();
            $config = $templateV2?->template_config ?? $templateV2?->published_config ?? config('credential-templates.version_2') ?? config('credential-templates.default');
            $templateVersion = $templateV2?->current_version ?? $templateV2?->published_version ?? 2;
        } else {
            $template = CredentialTemplate::query()
                ->where('slug', CredentialTemplateController::SLUG_V1)
                ->first();

            $config = $template?->published_config ?? config('credential-templates.default');
            $templateVersion = $template?->published_version ?? 1;
        }

        $persona = $elemento->persona;
        $nombreOficial = $persona?->nombre;
        $nombreSolicitado = $elemento->datos_solicitados['nombre'] ?? ($elemento->datos_originales_fila['NOMBRE'] ?? null);
        $nombre = $nombreOficial ?: ($nombreSolicitado ?: '—');
        $categoria = $persona?->categoria ?? ($elemento->datos_solicitados['categoria'] ?? '—');
        $subdireccion = $persona?->subdireccion ?? 'SUBDIRECCIÓN OPERATIVA';
        $ubicacion = $persona?->municipio ?? ($elemento->datos_solicitados['municipio'] ?? 'ESTADO DE MÉXICO');
        $credencial = $elemento->credencialEmitida;
        $solicitud = $elemento->solicitud;

        $armasAsignadas = array_values(array_filter([$elemento->armaCorta, $elemento->armaLarga]));
        if (! empty($armasAsignadas)) {
            $clases = [];
            $calibres = [];
            $marcas = [];
            $matriculas = [];
            $rfas = [];
            $resguardos = [];
            $modelos = [];
            foreach ($armasAsignadas as $a) {
                $clases[] = (string) ($a->clase_arma ?? ($a->tipo_arma ?? '—'));
                $calibres[] = (string) ($a->calibre ?? '—');
                $marcas[] = (string) ($a->marca ?? '—');
                $matriculas[] = (string) ($a->matricula ?? '—');
                $rfas[] = (string) ($a->rfa ?? '—');
                $resguardos[] = (string) ($a->resguardo ?? '—');
                $modelos[] = (string) ($a->modelo ?? '—');
            }
            $claseArma = implode("\n", $clases);
            $calibre = implode("\n", $calibres);
            $marca = implode("\n", $marcas);
            $matricula = implode("\n", $matriculas);
            $rfa = implode("\n", $rfas);
            $resguardo = implode("\n", $resguardos);
            $modelo = implode("\n", $modelos);
            $tipoArma = count($armasAsignadas) > 1 ? 'CORTA / LARGA' : ($elemento->armaCorta ? 'CORTA' : 'LARGA');
        } else {
            $arma = $elemento->armaCorta ?? $elemento->armaLarga;
            $matricula = $arma?->matricula ?? ($elemento->matricula_corta ?? ($elemento->matricula_larga ?? 'SIN ASIGNACIÓN'));
            $tipoArma = $arma?->tipo_arma ?? ($elemento->armaCorta ? 'CORTA' : ($elemento->armaLarga ? 'LARGA' : 'NO ASIGNADA'));
            $claseArma = $arma?->clase_arma ?? 'NO ASIGNADA';
            $marca = $arma?->marca ?? 'NO ASIGNADA';
            $modelo = $arma?->modelo ?? 'NO ASIGNADO';
            $calibre = $arma?->calibre ?? 'NO ASIGNADO';
            $rfa = $arma?->rfa ?? 'NO ASIGNADO';
            $resguardo = $arma?->resguardo ?? 'NO ASIGNADO';
        }

        $vigenciaRecord = $persona?->vigencias?->first();
        $fechaElaboracion = $vigenciaRecord?->fecha_elaboracion?->format('d/m/Y') ?? now()->format('d/m/Y');
        $fechaVigencia = $vigenciaRecord?->fecha_vigencia?->format('d/m/Y') ?? now()->addYears(2)->format('d/m/Y');
        $vigencia = "Del {$fechaElaboracion} al {$fechaVigencia}";

        $exp = $elemento->expedienteBiometrico;
        $biometricos = [
            'foto' => [
                'label' => 'Fotografía Facial',
                'existe' => (bool) ($exp?->foto && $exp->foto->es_vigente),
                'url' => ($exp?->foto && $exp->foto->es_vigente) ? route('impresion.elemento.biometrico.stream', ['elemento' => $elemento->id, 'categoria' => 'foto']) : null,
                'sha256' => $exp?->foto?->sha256,
                'nombre_archivo' => $exp?->foto?->nombre_archivo,
                'tamano_bytes' => $exp?->foto?->tamano_bytes,
                'version' => $exp?->foto?->version_secuencia ?? 1,
            ],
            'firma' => [
                'label' => 'Firma del Elemento',
                'existe' => (bool) ($exp?->firma && $exp->firma->es_vigente),
                'url' => ($exp?->firma && $exp->firma->es_vigente) ? route('impresion.elemento.biometrico.stream', ['elemento' => $elemento->id, 'categoria' => 'firma']) : null,
                'sha256' => $exp?->firma?->sha256,
                'nombre_archivo' => $exp?->firma?->nombre_archivo,
                'tamano_bytes' => $exp?->firma?->tamano_bytes,
                'version' => $exp?->firma?->version_secuencia ?? 1,
            ],
            'pulgar_derecho' => [
                'label' => 'Huella Pulgar Derecho',
                'existe' => (bool) ($exp?->pulgarDerecho && $exp->pulgarDerecho->es_vigente),
                'url' => ($exp?->pulgarDerecho && $exp->pulgarDerecho->es_vigente) ? route('impresion.elemento.biometrico.stream', ['elemento' => $elemento->id, 'categoria' => 'pulgar_derecho']) : null,
                'sha256' => $exp?->pulgarDerecho?->sha256,
                'nombre_archivo' => $exp?->pulgarDerecho?->nombre_archivo,
                'tamano_bytes' => $exp?->pulgarDerecho?->tamano_bytes,
                'version' => $exp?->pulgarDerecho?->version_secuencia ?? 1,
            ],
            'pulgar_izquierdo' => [
                'label' => 'Huella Pulgar Izquierdo',
                'existe' => (bool) ($exp?->pulgarIzquierdo && $exp->pulgarIzquierdo->es_vigente),
                'url' => ($exp?->pulgarIzquierdo && $exp->pulgarIzquierdo->es_vigente) ? route('impresion.elemento.biometrico.stream', ['elemento' => $elemento->id, 'categoria' => 'pulgar_izquierdo']) : null,
                'sha256' => $exp?->pulgarIzquierdo?->sha256,
                'nombre_archivo' => $exp?->pulgarIzquierdo?->nombre_archivo,
                'tamano_bytes' => $exp?->pulgarIzquierdo?->tamano_bytes,
                'version' => $exp?->pulgarIzquierdo?->version_secuencia ?? 1,
            ],
        ];

        // Mapeo de recursos institucionales fijos en plantilla (firmas institucionales)
        $recursosPlantilla = [];
        foreach (['front', 'rear'] as $face) {
            foreach ($config['faces'][$face]['elements'] ?? [] as $el) {
                if (! empty($el['asset_path'])) {
                    $recursosPlantilla[$el['id']] = route('impresion.plantilla.recurso', ['filename' => $el['asset_path']]);
                }
            }
        }

        $completos = collect($biometricos)->filter(fn ($b) => $b['existe'])->count();

        // Trazabilidad inmutable de visualización previa
        BiometricoAuditoria::registrarAcceso(
            evento: 'vista_previa_credencial',
            actor: $user,
            expediente: $exp,
            elemento: $elemento,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            detalles: [
                'elemento_id' => $elemento->id,
                'cuip' => $elemento->cuip,
                'biometria_completa' => $completos === 4,
            ]
        );

        return response()->json([
            'success' => true,
            'elemento' => [
                'id' => (int) $elemento->id,
                'cuip' => (string) $elemento->cuip,
                'nombre' => (string) $nombre,
                'categoria' => (string) $categoria,
                'numero_fila' => $elemento->numero_fila,
                'tarjeta' => (string) ($solicitud?->numero_tarjeta ?? '—'),
                'oficio' => (string) ($solicitud?->numero_oficio ?? '—'),
                'folio' => $credencial?->folio,
                'ambito' => $elemento->ambito_biometrico?->value ?? 'NO_RESUELTO',
                'estado_tramite' => (string) $elemento->estado_tramite,
                'puede_imprimir' => $elemento->estado_tramite === 'listo_impresion' && ! empty($credencial?->folio),
                'inmutable' => $credencial?->estado_emision === 'emitida',
            ],
            'template' => [
                'version' => $templateVersion,
                'width_mm' => $config['width_mm'] ?? 54,
                'height_mm' => $config['height_mm'] ?? 86,
                'config' => $config,
                'front_bg' => asset('credentials/front.png'),
                'rear_bg' => asset('credentials/rear.png'),
                'recursos' => $recursosPlantilla,
                'available_versions' => [1, 2],
            ],
            'campos_valores' => [
                'folio' => $credencial?->folio ?? 'PENDIENTE',
                'numero_tarjeta' => $solicitud?->numero_tarjeta ?? '—',
                'cuip' => (string) $elemento->cuip,
                'nombre' => (string) $nombre,
                'categoria' => (string) $categoria,
                'subdireccion' => (string) $subdireccion,
                'ubicacion' => (string) $ubicacion,
                'fecha_elaboracion' => $fechaElaboracion,
                'vigencia' => $vigencia,
                'matricula' => (string) $matricula,
                'tipo_arma' => (string) $tipoArma,
                'clase_arma' => (string) $claseArma,
                'marca' => (string) $marca,
                'modelo' => (string) $modelo,
                'calibre' => (string) $calibre,
                'rfa' => (string) $rfa,
                'resguardo' => (string) $resguardo,
                'codigo_barras' => (string) $elemento->cuip,
                'texto_fijo' => '',
            ],
            'biometricos' => $biometricos,
            'semaforo' => [
                'completos' => $completos,
                'total' => 4,
                'es_completo' => $completos === 4,
                'faltantes' => collect($biometricos)->filter(fn ($b) => ! $b['existe'])->keys()->values()->all(),
            ],
        ]);
    }

    /**
     * Entrega el flujo binario seguro de un archivo biométrico institucional
     * asociado al expediente del elemento.
     */
    public function streamBiometrico(
        Request $request,
        SolicitudElemento $elemento,
        string $categoria,
        BiometricDeliveryService $deliveryService
    ) {
        $user = $request->user();
        abort_unless($user && $user->can('viewPreview', $elemento), 403, 'No autorizado para visualizar este recurso biométrico.');

        $cat = match (strtolower(trim($categoria))) {
            'foto', 'fotografia' => BiometricoCategoria::FOTO,
            'firma' => BiometricoCategoria::FIRMA,
            'pulgar_derecho' => BiometricoCategoria::PULGAR_DERECHO,
            'pulgar_izquierdo' => BiometricoCategoria::PULGAR_IZQUIERDO,
            default => abort(404, 'Categoría biométrica no reconocida.'),
        };

        $expediente = $elemento->expedienteBiometrico;
        abort_unless($expediente !== null, 404, 'El elemento no cuenta con expediente biométrico registrado.');

        $columna = $cat->columnaExpediente();
        $archivoId = $expediente->getAttribute($columna);
        abort_unless($archivoId !== null, 404, 'El slot biométrico no tiene ningún archivo asignado.');

        $archivo = BiometricoArchivo::findOrFail($archivoId);

        return $deliveryService->streamArchivo($archivo, $user, $request, $expediente);
    }

    /**
     * Endpoint asíncrono para actualizar o cargar un archivo biométrico en caliente.
     */
    public function actualizarBiometrico(
        UpdateBiometricoElementoRequest $request,
        SolicitudElemento $elemento,
        BiometricUpdateService $updateService
    ): JsonResponse {
        $tipo = $request->input('tipo_biometrico');
        $cat = match (strtolower(trim((string) $tipo))) {
            'foto', 'fotografia' => BiometricoCategoria::FOTO,
            'firma' => BiometricoCategoria::FIRMA,
            'pulgar_derecho' => BiometricoCategoria::PULGAR_DERECHO,
            'pulgar_izquierdo' => BiometricoCategoria::PULGAR_IZQUIERDO,
        };

        $file = $request->file('archivo');
        $motivo = (string) $request->input('motivo_cambio');

        $resultado = $updateService->actualizarBiometrico(
            $elemento,
            $cat,
            $file,
            $motivo,
            $request->user()
        );

        $streamUrl = route('impresion.elemento.biometrico.stream', [
            'elemento' => $elemento->id,
            'categoria' => $cat->value,
        ]) . '?t=' . time();

        return response()->json([
            'success' => true,
            'message' => 'Archivo biométrico actualizado y versionado correctamente.',
            'categoria' => $cat->value,
            'sha256' => $resultado['archivo']->sha256,
            'version' => $resultado['archivo']->version_secuencia,
            'es_completo' => $resultado['expediente']->es_completo,
            'total_biometricos' => $resultado['expediente']->total_biometricos,
            'estado_tramite' => $resultado['elemento']->estado_tramite,
            'stream_url' => $streamUrl,
        ]);
    }
}
