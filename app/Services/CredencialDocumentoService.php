<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Controllers\CredentialTemplateController;
use App\Models\CredentialTemplate;
use App\Models\CredentialTemplateVersion;
use App\Models\SolicitudElemento;

/**
 * Entradas documentales de una credencial real: plantilla publicada y datos variables.
 * Trasladado desde CredentialTemplateController (printPreview/buildElementData) para que la
 * preparación de impresión congele exactamente lo que se renderiza.
 */
class CredencialDocumentoService
{
    /**
     * Plantilla aprobada para impresión (la misma regla que printPreview: solo la versión publicada).
     *
     * @return array{config: array<string, mixed>|null, version: int, template_id: int|null, version_id: int|null, origen: string}
     */
    public function plantillaPublicada(?string $versionSolicitada): array
    {
        $esV2 = in_array((string) $versionSolicitada, ['2', 'v2', 'version_2'], true);
        $template = CredentialTemplate::query()
            ->where('slug', $esV2 ? CredentialTemplateController::SLUG_V2 : CredentialTemplateController::SLUG_V1)
            ->first();

        $configArchivo = $esV2
            ? (config('credential-templates.version_2') ?? config('credential-templates.default'))
            : config('credential-templates.default');
        $config = $template?->published_config ?? $configArchivo;
        $version = (int) ($template?->published_version ?? ($esV2 ? 2 : 1));
        $versionId = ($template && $template->published_config !== null && $template->published_version !== null)
            ? CredentialTemplateVersion::query()
                ->where('credential_template_id', $template->id)
                ->where('version', $template->published_version)
                ->value('id')
            : null;

        return [
            'config' => $config,
            'version' => $version,
            'template_id' => $template?->id,
            'version_id' => $versionId !== null ? (int) $versionId : null,
            'origen' => $template?->published_config !== null ? 'version_bd' : 'config_archivo',
        ];
    }

    public function tieneDimensionesDeTarjeta(?array $config): bool
    {
        return is_array($config)
            && isset($config['width_mm'], $config['height_mm'])
            && (float) $config['width_mm'] === 54.0
            && (float) $config['height_mm'] === 86.0;
    }

    /**
     * Campos que la plantilla renderiza a partir de datos (todo elemento no estático).
     *
     * @return list<string>
     */
    public function camposRenderizados(array $config): array
    {
        $campos = [];
        foreach ($config['faces'] ?? [] as $cara) {
            foreach ($cara['elements'] ?? [] as $elemento) {
                $tipo = $elemento['kind'] ?? null;
                if ($tipo !== 'static' && isset($elemento['field']) && is_string($elemento['field'])) {
                    $campos[$elemento['field']] = true;
                }
            }
        }
        $campos = array_keys($campos);
        sort($campos, SORT_STRING);

        return $campos;
    }

    /**
     * Datos variables de la credencial y lista de campos que salieron de un valor por defecto
     * (no de los registros). Valores idénticos a los que mostraba buildElementData.
     *
     * @return array{datos: array<string, string>, por_defecto: list<string>}
     */
    public function datosVariables(SolicitudElemento $elemento): array
    {
        $porDefecto = [];
        $usa = static function (string $campo) use (&$porDefecto): void {
            $porDefecto[$campo] = true;
        };

        $persona = $elemento->persona;
        $nombreOficial = $persona?->nombre;
        $nombreSolicitado = $elemento->datos_solicitados['nombre'] ?? ($elemento->datos_originales_fila['NOMBRE'] ?? null);
        $nombre = $nombreOficial ?: ($nombreSolicitado ?: null);
        if ($nombre === null) {
            $usa('nombre');
            $nombre = '—';
        }
        $categoria = $persona?->categoria ?? ($elemento->datos_solicitados['categoria'] ?? null);
        if ($categoria === null) {
            $usa('categoria');
            $categoria = '—';
        }
        $subdireccion = $persona?->subdireccion;
        if ($subdireccion === null) {
            $usa('subdireccion');
            $subdireccion = 'SUBDIRECCIÓN OPERATIVA';
        }
        $ubicacion = $persona?->municipio ?? ($elemento->datos_solicitados['municipio'] ?? null);
        if ($ubicacion === null) {
            $usa('ubicacion');
            $ubicacion = 'ESTADO DE MÉXICO';
        }
        $credencial = $elemento->credencialEmitida;
        $solicitud = $elemento->solicitud;

        $armas = [];
        $armasAsignadas = array_values(array_filter([$elemento->armaCorta, $elemento->armaLarga]));
        $columnas = ['clase_arma' => 'clase_arma', 'calibre' => 'calibre', 'marca' => 'marca', 'matricula' => 'matricula', 'rfa' => 'rfa', 'resguardo' => 'resguardo', 'modelo' => 'modelo'];
        if (! empty($armasAsignadas)) {
            foreach ($columnas as $campo => $columna) {
                $valores = [];
                foreach ($armasAsignadas as $arma) {
                    $valor = $campo === 'clase_arma' ? ($arma->clase_arma ?? ($arma->tipo_arma ?? null)) : $arma->{$columna};
                    if ($valor === null) {
                        $usa($campo);
                        $valor = '—';
                    }
                    $valores[] = (string) $valor;
                }
                $armas[$campo] = implode("\n", $valores);
            }
            $armas['tipo_arma'] = count($armasAsignadas) > 1 ? 'CORTA / LARGA' : ($elemento->armaCorta ? 'CORTA' : 'LARGA');
        } else {
            $arma = $elemento->armaCorta ?? $elemento->armaLarga;
            $matricula = $arma?->matricula ?? ($elemento->matricula_corta ?? ($elemento->matricula_larga ?? null));
            if ($matricula === null) {
                $usa('matricula');
                $matricula = 'SIN ASIGNACIÓN';
            }
            $armas['matricula'] = (string) $matricula;
            $tipo = $arma?->tipo_arma ?? ($elemento->armaCorta ? 'CORTA' : ($elemento->armaLarga ? 'LARGA' : null));
            if ($tipo === null) {
                $usa('tipo_arma');
                $tipo = 'NO ASIGNADA';
            }
            $armas['tipo_arma'] = (string) $tipo;
            foreach (['clase_arma' => 'NO ASIGNADA', 'marca' => 'NO ASIGNADA', 'modelo' => 'NO ASIGNADO', 'calibre' => 'NO ASIGNADO', 'rfa' => 'NO ASIGNADO', 'resguardo' => 'NO ASIGNADO'] as $campo => $defecto) {
                $valor = $arma?->{$campo};
                if ($valor === null) {
                    $usa($campo);
                    $valor = $defecto;
                }
                $armas[$campo] = (string) $valor;
            }
        }

        $vigenciaRecord = $persona?->vigencias?->first();
        $fechaElaboracion = $vigenciaRecord?->fecha_elaboracion?->format('d/m/Y');
        if ($fechaElaboracion === null) {
            $usa('fecha_elaboracion');
            $fechaElaboracion = now()->format('d/m/Y');
        }
        $fechaVigencia = $vigenciaRecord?->fecha_vigencia?->format('d/m/Y');
        if ($fechaVigencia === null) {
            $usa('vigencia');
            $fechaVigencia = now()->addYears(2)->format('d/m/Y');
        }

        $folio = $credencial?->folio;
        if ($folio === null) {
            $usa('folio');
            $folio = 'PENDIENTE DE EMISIÓN';
        }
        $numeroTarjeta = $solicitud?->numero_tarjeta;
        if ($numeroTarjeta === null) {
            $usa('numero_tarjeta');
            $numeroTarjeta = '—';
        }

        $datos = [
            'folio' => (string) $folio,
            'numero_tarjeta' => (string) $numeroTarjeta,
            'cuip' => (string) $elemento->cuip,
            'nombre' => (string) $nombre,
            'categoria' => (string) $categoria,
            'fecha_elaboracion' => $fechaElaboracion,
            'vigencia' => "Del {$fechaElaboracion} al {$fechaVigencia}",
            'fotografia' => 'FOTOGRAFÍA FACIAL REGISTRADA',
            'firma_secretario' => 'FIRMA DEL SECRETARIO',
            'firma_oficial_mayor' => 'FIRMA OFICIAL MAYOR',
            'firma_titular' => 'FIRMA DEL ELEMENTO',
            'subdireccion' => (string) $subdireccion,
            'ubicacion' => (string) $ubicacion,
            'tipo_arma' => $armas['tipo_arma'],
            'clase_arma' => $armas['clase_arma'],
            'calibre' => $armas['calibre'],
            'marca' => $armas['marca'],
            'matricula' => $armas['matricula'],
            'rfa' => $armas['rfa'],
            'resguardo' => $armas['resguardo'],
            'modelo' => $armas['modelo'],
            'huella_izquierda' => 'HUELLA IZQUIERDA REGISTRADA',
            'huella_derecha' => 'HUELLA DERECHA REGISTRADA',
            'codigo_barras' => (string) $elemento->cuip,
            'texto_fijo' => '',
            'firma_persona' => 'FIRMA DEL TITULAR',
            'firma_autorizada' => 'FIRMA AUTORIZADA',
            'nombre_firmante' => 'FIRMANTE OFICIAL',
            'aviso_legal' => 'Esta credencial ampara la portación de armas de fuego en términos de la LOC 139.',
        ];

        $porDefecto = array_keys($porDefecto);
        sort($porDefecto, SORT_STRING);

        return ['datos' => $datos, 'por_defecto' => $porDefecto];
    }
}
