<?php

namespace App\Http\Controllers;

use App\Models\CredentialTemplate;
use App\Models\CredentialTemplateVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CredentialTemplateController extends Controller
{
    public const SLUG_V1 = 'credencial-loc-vertical';
    public const SLUG_V2 = 'credencial-loc-vertical-v2';
    private const TEMPLATE_SLUG = self::SLUG_V1;

    public static function resolveSlugByVersion(int|string|null $version): string
    {
        $versionStr = (string) $version;
        if (in_array($versionStr, ['2', 'v2', 'version_2'], true)) {
            return self::SLUG_V2;
        }

        return self::SLUG_V1;
    }

    public static function resolveVersionNumber(int|string|null $version): int
    {
        $versionStr = (string) $version;
        if (in_array($versionStr, ['2', 'v2', 'version_2'], true)) {
            return 2;
        }

        return 1;
    }

    public static function resolveDefaultConfig(int|string|null $version): array
    {
        if (self::resolveVersionNumber($version) === 2) {
            return config('credential-templates.version_2') ?? config('credential-templates.default');
        }

        return config('credential-templates.default');
    }

    public function editor(Request $request): View
    {
        $requestedVersion = $request->query('template_version', $request->query('template', '1'));
        $selectedVersion = self::resolveVersionNumber($requestedVersion);
        $slug = self::resolveSlugByVersion($selectedVersion);

        $template = CredentialTemplate::query()
            ->where('slug', $slug)
            ->first();

        $defaultConfig = self::resolveDefaultConfig($selectedVersion);
        $templateConfig = $template?->template_config ?? $template?->published_config ?? $defaultConfig;

        return view('modules.credential-template-editor', [
            'template' => $template,
            'templateConfig' => $templateConfig,
            'defaultConfig' => $defaultConfig,
            'selectedTemplateVersion' => $selectedVersion,
            'fieldLabels' => config('credential-templates.fields'),
            'demoData' => $this->demoData(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $validated = $this->validateTemplate($request);
        $config = $validated['config'];
        $selectedVersion = self::resolveVersionNumber($request->input('template_version', 1));
        $slug = self::resolveSlugByVersion($selectedVersion);

        $template = DB::transaction(function () use ($request, $config, $validated, $slug): CredentialTemplate {
            $template = CredentialTemplate::query()
                ->where('slug', $slug)
                ->lockForUpdate()
                ->first();

            if (! $template) {
                $template = new CredentialTemplate([
                    'slug' => $slug,
                    'current_version' => 0,
                ]);
            }

            $nextVersion = $template->current_version + 1;
            $template->fill([
                'name' => $config['name'],
                'status' => 'draft',
                'current_version' => $nextVersion,
                'template_config' => $config,
                'updated_by' => $request->user()->getKey(),
            ]);
            $template->save();

            $template->versions()->create([
                'version' => $nextVersion,
                'template_config' => $config,
                'change_summary' => $validated['change_summary'] ?? null,
                'created_by' => $request->user()->getKey(),
            ]);

            return $template;
        });

        return response()->json([
            'message' => 'La plantilla se guardó como borrador.',
            'version' => $template->current_version,
            'template_version' => $selectedVersion,
        ]);
    }

    public function uploadResource(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'face' => ['required', Rule::in(['front', 'rear'])],
            'element_id' => ['required', Rule::in([
                'foto_principal',
                'foto_secundaria',
                'firma_secretario',
                'firma_oficial_mayor',
                'firma_titular',
                'huella_izquierda',
                'huella_derecha',
            ])],
            'resource' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'dimensions:max_width=4096,max_height=4096', 'max:2048'],
        ]);

        $file = $validated['resource'];
        $imageSize = getimagesize($file->getPathname());
        if ($imageSize === false || $imageSize[0] < 1 || $imageSize[1] < 1) {
            throw ValidationException::withMessages([
                'resource' => 'No se pudo validar la imagen seleccionada.',
            ]);
        }

        $filename = Str::uuid()->toString().'.'.$file->extension();
        $storedPath = $file->storeAs('credential-template-resources', $filename, 'local');
        if (! is_string($storedPath)) {
            throw new \RuntimeException('No se pudo almacenar el recurso de la plantilla.');
        }

        return response()->json([
            'message' => 'El recurso quedó almacenado y puede guardarse en una versión de la plantilla.',
            'asset_path' => $filename,
            'asset_url' => route('impresion.plantilla.recurso', ['filename' => $filename]),
            'image_aspect' => $imageSize[0] / $imageSize[1],
        ]);
    }

    public function resource(string $filename)
    {
        abort_unless(
            preg_match('/\A[A-Za-z0-9_-]{36}\.(?:png|jpe?g|webp)\z/i', $filename) === 1,
            404,
        );

        $path = 'credential-template-resources/'.$filename;
        if (! Storage::disk('local')->exists($path)) {
            $legacyPath = storage_path('app/'.$path);
            abort_unless(file_exists($legacyPath), 404);

            return response()->file($legacyPath, [
                'Cache-Control' => 'public, max-age=86400',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return Storage::disk('local')->response($path, $filename, [
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function versions(Request $request): JsonResponse
    {
        $selectedVersion = self::resolveVersionNumber($request->query('template_version', 1));
        $slug = self::resolveSlugByVersion($selectedVersion);

        $template = CredentialTemplate::query()
            ->where('slug', $slug)
            ->first();

        if (! $template) {
            return response()->json(['versions' => []]);
        }

        return response()->json([
            'versions' => $template->versions()
                ->latest('version')
                ->get(['id', 'version', 'change_summary', 'created_by', 'approved_at', 'created_at'])
                ->map(fn (CredentialTemplateVersion $version): array => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'summary' => $version->change_summary,
                    'created_by' => $version->created_by,
                    'approved' => $version->approved_at !== null,
                    'created_at' => $version->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function restore(Request $request, CredentialTemplateVersion $version): JsonResponse
    {
        $template = CredentialTemplate::query()
            ->whereKey($version->credential_template_id)
            ->firstOrFail();

        abort_unless($version->credential_template_id === $template->id, 404);

        $newVersion = DB::transaction(function () use ($request, $template, $version): int {
            $lockedTemplate = CredentialTemplate::query()
                ->whereKey($template->id)
                ->lockForUpdate()
                ->firstOrFail();
            $nextVersion = $lockedTemplate->current_version + 1;
            $restoredConfig = $version->template_config;
            foreach ($restoredConfig['faces'] as &$face) {
                foreach ($face['elements'] as &$element) {
                    $element['locked'] = ($element['locked'] ?? false) === true;
                    unset($element['parameter']);
                }
                unset($element);
            }
            unset($face);

            $lockedTemplate->fill([
                'name' => $restoredConfig['name'],
                'status' => 'draft',
                'current_version' => $nextVersion,
                'template_config' => $restoredConfig,
                'updated_by' => $request->user()->getKey(),
            ])->save();

            $lockedTemplate->versions()->create([
                'version' => $nextVersion,
                'template_config' => $restoredConfig,
                'change_summary' => 'Restauración de la versión '.$version->version,
                'created_by' => $request->user()->getKey(),
            ]);

            return $nextVersion;
        });

        return response()->json([
            'message' => 'La versión seleccionada se restauró como un nuevo borrador.',
            'version' => $newVersion,
            'config' => $version->template_config,
        ]);
    }

    public function approve(Request $request, CredentialTemplateVersion $version): JsonResponse
    {
        if (! $this->hasCardDimensions($version->template_config)) {
            throw ValidationException::withMessages([
                'dimensions' => 'La plantilla debe conservar el formato físico de 54 × 86 mm.',
            ]);
        }

        $template = CredentialTemplate::query()
            ->whereKey($version->credential_template_id)
            ->firstOrFail();

        abort_unless($version->credential_template_id === $template->id, 404);

        DB::transaction(function () use ($request, $template, $version): void {
            $version->forceFill([
                'approved_by' => $request->user()->getKey(),
                'approved_at' => now(),
            ])->save();

            $template->forceFill([
                'published_config' => $version->template_config,
                'published_version' => $version->version,
                'status' => 'published',
                'approved_by' => $request->user()->getKey(),
                'approved_at' => now(),
            ])->save();
        });

        return response()->json([
            'message' => 'La versión aprobada quedó publicada para la vista de impresión.',
            'version' => $version->version,
        ]);
    }

    public function printPreview(Request $request): View
    {
        $requestedVersion = (string) $request->input('template_version', $request->input('template', ''));
        if (in_array($requestedVersion, ['2', 'v2', 'version_2'], true)) {
            $templateV2 = CredentialTemplate::query()
                ->where('slug', self::SLUG_V2)
                ->first();
            $config = $templateV2?->template_config ?? $templateV2?->published_config ?? config('credential-templates.version_2') ?? config('credential-templates.default');
            $templateVersion = $templateV2?->current_version ?? $templateV2?->published_version ?? 2;
        } else {
            $template = CredentialTemplate::query()
                ->where('slug', self::SLUG_V1)
                ->first();
            $config = $template?->published_config ?? config('credential-templates.default');
            $templateVersion = $template?->published_version ?? 1;
        }

        abort_unless($config, 409, 'Aún no existe una versión aprobada para impresión.');
        abort_unless(
            $this->hasCardDimensions($config),
            409,
            'La impresión requiere una versión aprobada en formato 54 × 86 mm.',
        );

        $elementoId = $request->query('elemento_id');
        $realData = null;
        $biometricUrls = [];
        $isRealElement = false;

        if ($elementoId && is_numeric($elementoId)) {
            $elemento = \App\Models\SolicitudElemento::with([
                'solicitud',
                'persona.vigencias',
                'armaCorta',
                'armaLarga',
                'credencialEmitida',
                'expedienteBiometrico.foto',
                'expedienteBiometrico.firma',
                'expedienteBiometrico.pulgarDerecho',
                'expedienteBiometrico.pulgarIzquierdo',
            ])->find($elementoId);

            if ($elemento) {
                $isRealElement = true;
                $realData = $this->buildElementData($elemento);
                $biometricUrls = $this->buildBiometricUrls($elemento);
            }
        }

        return view('modules.credential-template-print', [
            'templateConfig' => $config,
            'templateVersion' => $templateVersion,
            'selectedTemplateVersion' => self::resolveVersionNumber($requestedVersion),
            'fieldLabels' => config('credential-templates.fields'),
            'demoData' => $realData ?? $this->demoData(),
            'isRealElement' => $isRealElement,
            'biometricUrls' => $biometricUrls,
        ]);
    }

    /**
     * Construye el diccionario de campos dinámicos para una credencial real.
     *
     * @return array<string, string>
     */
    private function buildElementData(\App\Models\SolicitudElemento $elemento): array
    {
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

        return [
            'folio' => $credencial?->folio ?? 'PENDIENTE DE EMISIÓN',
            'numero_tarjeta' => $solicitud?->numero_tarjeta ?? '—',
            'cuip' => (string) $elemento->cuip,
            'nombre' => (string) $nombre,
            'categoria' => (string) $categoria,
            'fecha_elaboracion' => $fechaElaboracion,
            'vigencia' => $vigencia,
            'fotografia' => 'FOTOGRAFÍA FACIAL REGISTRADA',
            'firma_secretario' => 'FIRMA DEL SECRETARIO',
            'firma_oficial_mayor' => 'FIRMA OFICIAL MAYOR',
            'firma_titular' => 'FIRMA DEL ELEMENTO',
            'subdireccion' => (string) $subdireccion,
            'ubicacion' => (string) $ubicacion,
            'tipo_arma' => (string) $tipoArma,
            'clase_arma' => (string) $claseArma,
            'calibre' => (string) $calibre,
            'marca' => (string) $marca,
            'matricula' => (string) $matricula,
            'rfa' => (string) $rfa,
            'resguardo' => (string) $resguardo,
            'modelo' => (string) $modelo,
            'huella_izquierda' => 'HUELLA IZQUIERDA REGISTRADA',
            'huella_derecha' => 'HUELLA DERECHA REGISTRADA',
            'codigo_barras' => (string) $elemento->cuip,
            'texto_fijo' => '',
            'firma_persona' => 'FIRMA DEL TITULAR',
            'firma_autorizada' => 'FIRMA AUTORIZADA',
            'nombre_firmante' => 'FIRMANTE OFICIAL',
            'aviso_legal' => 'Esta credencial ampara la portación de armas de fuego en términos de la LOC 139.',
        ];
    }

    /**
     * Construye las URLs de streaming seguro para los biométricos del elemento.
     *
     * @return array<string, string>
     */
    private function buildBiometricUrls(\App\Models\SolicitudElemento $elemento): array
    {
        $exp = $elemento->expedienteBiometrico;
        if (! $exp) {
            return [];
        }

        $urls = [];
        if ($exp->foto && $exp->foto->es_vigente) {
            $urls['fotografia'] = route('impresion.elemento.biometrico.stream', ['elemento' => $elemento->id, 'categoria' => 'foto']);
        }
        if ($exp->firma && $exp->firma->es_vigente) {
            $urls['firma_titular'] = route('impresion.elemento.biometrico.stream', ['elemento' => $elemento->id, 'categoria' => 'firma']);
            $urls['firma_persona'] = $urls['firma_titular'];
        }
        if ($exp->pulgarDerecho && $exp->pulgarDerecho->es_vigente) {
            $urls['huella_derecha'] = route('impresion.elemento.biometrico.stream', ['elemento' => $elemento->id, 'categoria' => 'pulgar_derecho']);
        }
        if ($exp->pulgarIzquierdo && $exp->pulgarIzquierdo->es_vigente) {
            $urls['huella_izquierda'] = route('impresion.elemento.biometrico.stream', ['elemento' => $elemento->id, 'categoria' => 'pulgar_izquierdo']);
        }

        return $urls;
    }

    /**
     * Endpoint JSON para previsualización estructurada de un elemento con plantilla y biométricos.
     */
    public function elementPreview(Request $request, \App\Models\SolicitudElemento $elemento): JsonResponse
    {
        return app(\App\Http\Controllers\PrintQueueController::class)->previsualizacionElemento(
            $request,
            $elemento,
            app(\App\Services\BiometricResolverService::class)
        );
    }

    private function validateTemplate(Request $request): array
    {
        $fieldKeys = array_keys(config('credential-templates.fields'));
        $rules = [
            'config' => 'required|array:name,width_mm,height_mm,faces,orientation,dpi,width_px,height_px',
            'config.name' => 'required|string|max:120',
            'config.width_mm' => 'required|numeric|in:54',
            'config.height_mm' => 'required|numeric|in:86',
            'config.orientation' => ['nullable', Rule::in(['vertical', 'portrait'])],
            'config.dpi' => 'nullable|integer',
            'config.width_px' => 'nullable|integer',
            'config.height_px' => 'nullable|integer',
            'config.faces' => 'required|array:front,rear',
            'config.faces.front' => 'required|array:background,elements',
            'config.faces.rear' => 'required|array:background,elements',
            'config.faces.front.background' => ['required', Rule::in(['front'])],
            'config.faces.rear.background' => ['required', Rule::in(['rear'])],
            'config.faces.front.elements' => 'required|array|min:1|max:40',
            'config.faces.rear.elements' => 'required|array|min:1|max:40',
            'change_summary' => 'nullable|string|max:255',
        ];

        foreach (['front', 'rear'] as $face) {
            $path = 'config.faces.'.$face.'.elements.*';
            $rules += [
                $path => 'required|array:id,kind,field,label,content,x,y,width,height,font_size,line_height,font_family,weight,color,align,visible,locked,optional,rotation,asset_path,image_aspect,border',
                $path.'.id' => 'required|string|max:50|distinct:strict',
                $path.'.kind' => ['required', Rule::in(['static', 'dynamic', 'image', 'barcode'])],
                $path.'.field' => ['nullable', Rule::in($fieldKeys)],
                $path.'.label' => 'nullable|string|max:120',
                $path.'.content' => 'nullable|string|max:1000',
                $path.'.x' => 'required|numeric|min:0|max:100',
                $path.'.y' => 'required|numeric|min:0|max:100',
                $path.'.width' => 'required|numeric|gt:0|lte:100',
                $path.'.height' => 'required|numeric|gt:0|lte:100',
                $path.'.font_size' => 'required|numeric|min:3|max:36',
                $path.'.line_height' => 'required|numeric|min:0.8|max:2',
                $path.'.font_family' => ['nullable', Rule::in(['Inter', 'Arial', 'sans-serif', 'Arial, Helvetica, sans-serif'])],
                $path.'.weight' => ['required', Rule::in([400, 600, 700, 800])],
                $path.'.color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                $path.'.align' => ['required', Rule::in(['left', 'center', 'right'])],
                $path.'.visible' => 'required|boolean',
                $path.'.locked' => 'required|boolean',
                $path.'.optional' => 'required|boolean',
                $path.'.rotation' => ['required', Rule::in([-90, 0, 90, 270])],
                $path.'.asset_path' => ['nullable', 'regex:/^[A-Za-z0-9_-]{36}\.(?:png|jpe?g|webp)$/i'],
                $path.'.image_aspect' => 'nullable|numeric|gt:0|lte:10',
                $path.'.border' => ['nullable', 'string', 'max:60'],
            ];
        }

        $validated = $request->validate($rules);
        $config = $validated['config'];

        if ((float) $config['width_mm'] !== 54.0 || (float) $config['height_mm'] !== 86.0) {
            throw ValidationException::withMessages([
                'config.width_mm' => 'El formato de esta plantilla está establecido en 54 × 86 mm.',
            ]);
        }

        if ($config['width_mm'] !== null) {
            $imageRatio = 639 / 1017;
            $physicalRatio = $config['width_mm'] / $config['height_mm'];

            if (abs($physicalRatio - $imageRatio) > 0.005) {
                throw ValidationException::withMessages([
                    'config.width_mm' => 'Las dimensiones físicas deben conservar la proporción del fondo institucional.',
                ]);
            }
        }

        foreach ($config['faces'] as $face) {
            foreach ($face['elements'] as $index => $element) {
                if ($element['x'] + $element['width'] > 100.01 || $element['y'] + $element['height'] > 100.01) {
                    throw ValidationException::withMessages([
                        "config.faces.*.elements.{$index}" => 'La posición y las dimensiones del elemento deben quedar dentro de la credencial.',
                    ]);
                }

                if ($element['kind'] === 'static' && ($element['field'] !== 'texto_fijo' || ! is_string($element['content']))) {
                    throw ValidationException::withMessages([
                        "config.faces.*.elements.{$index}.content" => 'Los textos fijos deben tener contenido editable y no estar vinculados a un dato personal.',
                    ]);
                }

                if ($element['kind'] === 'dynamic' && ($element['field'] === null || $element['field'] === 'texto_fijo')) {
                    throw ValidationException::withMessages([
                        "config.faces.*.elements.{$index}.field" => 'Los campos dinámicos requieren una vinculación de datos válida.',
                    ]);
                }

                if ($element['kind'] === 'image' && ! in_array($element['field'], [
                    'fotografia',
                    'huella_izquierda',
                    'huella_derecha',
                    'firma_secretario',
                    'firma_oficial_mayor',
                    'firma_titular',
                    'firma_persona',
                    'firma_autorizada',
                ], true)) {
                    throw ValidationException::withMessages([
                        "config.faces.*.elements.{$index}.field" => 'Los recursos de imagen requieren un campo de fotografía, firma o biometría.',
                    ]);
                }

                if ($element['asset_path'] !== null) {
                    $existsInStorage = Storage::disk('local')->exists('credential-template-resources/'.$element['asset_path'])
                        || file_exists(storage_path('app/credential-template-resources/'.$element['asset_path']));
                    if ($element['kind'] !== 'image' || ! $existsInStorage) {
                        throw ValidationException::withMessages([
                            "config.faces.*.elements.{$index}.asset_path" => 'El recurso debe ser una imagen cargada y almacenada para este editor.',
                        ]);
                    }
                }
            }
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function hasCardDimensions(array $config): bool
    {
        return isset($config['width_mm'], $config['height_mm'])
            && (float) $config['width_mm'] === 54.0
            && (float) $config['height_mm'] === 86.0;
    }

    /**
     * Safe values used only for the editor and print proof; no personal records are queried.
     *
     * @return array<string, string>
     */
    private function demoData(): array
    {
        return [
            'folio' => 'FOLIO DEMO',
            'numero_tarjeta' => 'TARJETA DEMO',
            'cuip' => 'CUIP-DEMO',
            'nombre' => 'PERSONA DE DEMOSTRACIÓN',
            'categoria' => 'CATEGORÍA DE PRUEBA',
            'fecha_elaboracion' => 'FECHA PENDIENTE',
            'vigencia' => 'VIGENCIA PENDIENTE',
            'fotografia' => 'FOTOGRAFÍA DEMO · NO DISPONIBLE',
            'firma_secretario' => 'FIRMA DEL SECRETARIO · NO DISPONIBLE',
            'firma_oficial_mayor' => 'FIRMA OFICIAL MAYOR · NO DISPONIBLE',
            'firma_titular' => 'FIRMA DEL ELEMENTO',
            'subdireccion' => 'SUBDIRECCIÓN PENDIENTE',
            'ubicacion' => 'UBICACIÓN PENDIENTE',
            'tipo_arma' => 'PENDIENTE',
            'clase_arma' => 'PENDIENTE',
            'calibre' => 'PENDIENTE',
            'marca' => 'PENDIENTE',
            'matricula' => 'PENDIENTE',
            'rfa' => 'PENDIENTE',
            'resguardo' => 'PENDIENTE',
            'modelo' => 'PENDIENTE',
            'huella_izquierda' => 'HUELLA IZQUIERDA · NO DISPONIBLE',
            'huella_derecha' => 'HUELLA DERECHA · NO DISPONIBLE',
            'codigo_barras' => 'CÓDIGO DE BARRAS · PENDIENTE DE IDENTIFICACIÓN',
            'texto_fijo' => '',
            'firma_persona' => 'FIRMA DEL TITULAR · NO DISPONIBLE',
            'firma_autorizada' => 'FIRMA AUTORIZADA · NO DISPONIBLE',
            'nombre_firmante' => 'FIRMANTE PENDIENTE',
            'aviso_legal' => 'TEXTO INSTITUCIONAL PENDIENTE DE VALIDACIÓN',
        ];
    }
}
