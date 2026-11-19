<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\SolicitudElemento;
use App\Support\CapturaTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBiometricoElementoRequest extends FormRequest
{
    /**
     * Determina si el usuario está autorizado para ejecutar esta solicitud.
     */
    public function authorize(): bool
    {
        /** @var SolicitudElemento|null $elemento */
        $elemento = $this->route('elemento');

        if (! $elemento instanceof SolicitudElemento) {
            return false;
        }

        return $this->user() !== null && $this->user()->can('updateBiometrics', $elemento);
    }

    /**
     * Reglas de validación aplicables a la actualización biométrica.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo_biometrico' => [
                'required',
                'string',
                Rule::in(['foto', 'fotografia', 'firma', 'pulgar_derecho', 'pulgar_izquierdo']),
            ],
            'archivo' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,bmp',
                'max:2048', // 2 MB máximo
            ],
            'motivo_cambio' => [
                'required',
                'string',
                'min:5',
                'max:500',
            ],
            // Elemento para el que se inició la captura en la estación (pad o lienzo).
            'elemento_captura_id' => [
                'sometimes',
                'integer',
            ],
            // Ticket de un solo uso emitido al iniciar una captura con el agente de estación (cámara Canon).
            'captura_ticket' => [
                'sometimes',
                'string',
                'max:1024',
            ],
        ];
    }

    /**
     * Validación estricta de encabezados binarios (magic bytes) para prevenir
     * archivos maliciosos renombrados como imágenes.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Una captura iniciada para un elemento nunca se guarda en otro, aunque el operador cambie de selección.
            $elemento = $this->route('elemento');
            if ($this->filled('elemento_captura_id') && $elemento instanceof SolicitudElemento
                && (int) $this->input('elemento_captura_id') !== (int) $elemento->id) {
                $v->errors()->add('elemento_captura_id', 'La captura se inició para otro elemento; no se guardó. Repita la captura con el elemento seleccionado.');

                return;
            }

            $file = $this->file('archivo');
            if ($file && $file->isValid()) {
                $realPath = $file->getRealPath();
                $imageInfo = @getimagesize($realPath);

                $allowedTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG];
                if (defined('IMAGETYPE_BMP')) {
                    $allowedTypes[] = IMAGETYPE_BMP;
                }

                if ($imageInfo === false || ! in_array($imageInfo[2], $allowedTypes, true)) {
                    $v->errors()->add('archivo', 'El archivo cargado no contiene una firma binaria (magic bytes) válida de imagen JPEG, PNG o BMP.');
                    return;
                }

                // Una imagen del modo simulación del agente nunca es evidencia biométrica, llegue por el canal que llegue.
                if ($imageInfo[2] === IMAGETYPE_PNG && str_contains((string) @file_get_contents($realPath, false, null, 0, 4096), CapturaTicket::MARCADOR_SIMULACION)) {
                    $v->errors()->add('archivo', 'La imagen proviene del modo simulación del agente de estación y no puede guardarse como evidencia.');

                    return;
                }

                // Verificación de dimensiones mínimas periciales
                if ($imageInfo[0] < 50 || $imageInfo[1] < 50) {
                    $v->errors()->add('archivo', 'Las dimensiones de la imagen son demasiado reducidas para el estándar pericial institucional.');

                    return;
                }

                if (strtolower((string) $this->input('tipo_biometrico')) === 'firma' && ! $this->tieneTrazoDeFirma($realPath)) {
                    $v->errors()->add('archivo', 'La imagen de firma está vacía o no contiene un trazo reconocible.');

                    return;
                }

                if ($this->filled('captura_ticket')) {
                    $this->validarCapturaDeAgente($v, $realPath, $imageInfo);
                }
            }
        });
    }

    /**
     * Captura del agente de estación (cámara o lector de huellas): el contenido debe decodificarse por completo,
     * tener un tamaño mínimo y no ser uniforme (objetivo tapado, cristal vacío). El ticket se consume solo si la
     * imagen es válida.
     *
     * @param  array<int|string, mixed>  $imageInfo
     */
    private function validarCapturaDeAgente(Validator $v, string $ruta, array $imageInfo): void
    {
        $tipo = strtolower((string) $this->input('tipo_biometrico'));
        $categoria = $tipo === 'fotografia' ? 'foto' : $tipo;
        if (! in_array($categoria, CapturaTicket::CATEGORIAS, true)) {
            $v->errors()->add('captura_ticket', 'Este componente biométrico no se captura con el agente de estación.');

            return;
        }
        // Solo se acepta un formato cuyo contenido completo pueda decodificarse aquí (el GD instalado puede no leer JPEG).
        $verificable = $imageInfo[2] === IMAGETYPE_PNG || ($imageInfo[2] === IMAGETYPE_JPEG && (imagetypes() & IMG_JPG) !== 0);
        if (! $verificable) {
            $v->errors()->add('archivo', 'El servidor no puede verificar el contenido de este formato; la captura debe enviarse en PNG.');

            return;
        }
        [$anchoMinimo, $altoMinimo] = $categoria === 'foto' ? [240, 320] : [200, 200];
        if ($imageInfo[0] < $anchoMinimo || $imageInfo[1] < $altoMinimo) {
            $v->errors()->add('archivo', "La imagen capturada es demasiado pequeña (mínimo {$anchoMinimo} × {$altoMinimo} px).");

            return;
        }

        $contenido = @file_get_contents($ruta);
        $imagen = $contenido !== false ? @imagecreatefromstring($contenido) : false;
        if ($imagen === false) {
            $v->errors()->add('archivo', 'La imagen capturada está dañada o incompleta.');

            return;
        }
        $uniforme = $this->esUniforme($imagen);
        imagedestroy($imagen);
        if ($uniforme) {
            $v->errors()->add('archivo', 'La imagen capturada está vacía o es uniforme: revise que el objetivo no esté tapado o que el dedo esté sobre el cristal del lector.');

            return;
        }

        /** @var SolicitudElemento $elemento */
        $elemento = $this->route('elemento');
        $motivo = CapturaTicket::consumir((string) $this->input('captura_ticket'), $elemento, $this->user(), $categoria);
        if ($motivo !== null) {
            $v->errors()->add('captura_ticket', $motivo);
        }
    }

    /** Desviación de luminancia casi nula sobre una muestra acotada de la imagen. */
    private function esUniforme(\GdImage $imagen): bool
    {
        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $paso = max(1, (int) ceil(sqrt(($ancho * $alto) / 40000)));
        $suma = 0.0;
        $sumaCuadrados = 0.0;
        $n = 0;
        for ($y = 0; $y < $alto; $y += $paso) {
            for ($x = 0; $x < $ancho; $x += $paso) {
                $c = imagecolorsforindex($imagen, imagecolorat($imagen, $x, $y));
                $luma = 0.299 * $c['red'] + 0.587 * $c['green'] + 0.114 * $c['blue'];
                $suma += $luma;
                $sumaCuadrados += $luma * $luma;
                $n++;
            }
        }
        $media = $suma / max(1, $n);

        return sqrt(max(0.0, $sumaCuadrados / max(1, $n) - $media * $media)) < 3.0;
    }

    /**
     * Comprueba en el servidor que la firma tenga trazo: píxeles opacos y oscuros sobre fondo claro o transparente.
     * Rechaza lienzos en blanco (captura sin firmar) y rellenos sólidos.
     */
    private function tieneTrazoDeFirma(string $ruta): bool
    {
        $contenido = @file_get_contents($ruta);
        $imagen = $contenido !== false ? @imagecreatefromstring($contenido) : false;
        if ($imagen === false) {
            return false;
        }

        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        // Muestreo acotado (~250 000 puntos) para no recorrer imágenes grandes píxel a píxel.
        $paso = max(1, (int) ceil(sqrt(($ancho * $alto) / 250000)));
        $muestras = 0;
        $tinta = 0;

        for ($y = 0; $y < $alto; $y += $paso) {
            for ($x = 0; $x < $ancho; $x += $paso) {
                $color = imagecolorsforindex($imagen, imagecolorat($imagen, $x, $y));
                $muestras++;
                $luma = 0.299 * $color['red'] + 0.587 * $color['green'] + 0.114 * $color['blue'];
                // alpha de GD: 0 opaco, 127 transparente.
                if ($color['alpha'] < 64 && $luma < 160) {
                    $tinta++;
                }
            }
        }

        imagedestroy($imagen);
        $proporcion = $muestras > 0 ? $tinta / $muestras : 0;

        return $tinta >= 20 && $proporcion <= 0.5;
    }

    /**
     * Mensajes de error personalizados.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo_biometrico.required' => 'Debe especificar el slot biométrico a actualizar.',
            'tipo_biometrico.in' => 'El tipo biométrico especificado no es válido.',
            'archivo.required' => 'Debe adjuntar un archivo de imagen válido.',
            'archivo.mimes' => 'El formato del archivo debe ser estrictamente JPG, JPEG, PNG o BMP.',
            'archivo.max' => 'El tamaño del archivo no puede exceder los 2 MB (2048 KB).',
            'motivo_cambio.required' => 'Es obligatorio registrar la justificación o motivo institucional del cambio.',
            'motivo_cambio.min' => 'El motivo del cambio debe contener al menos 5 caracteres.',
            'motivo_cambio.max' => 'El motivo del cambio no puede exceder los 500 caracteres.',
        ];
    }
}

