<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\SolicitudElemento;
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
        ];
    }

    /**
     * Validación estricta de encabezados binarios (magic bytes) para prevenir
     * archivos maliciosos renombrados como imágenes.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
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

                // Verificación de dimensiones mínimas periciales
                if ($imageInfo[0] < 50 || $imageInfo[1] < 50) {
                    $v->errors()->add('archivo', 'Las dimensiones de la imagen son demasiado reducidas para el estándar pericial institucional.');
                }
            }
        });
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
            'archivo.mimes' => 'El formato del archivo debe ser estrictamente JPG, JPEG o PNG.',
            'archivo.max' => 'El tamaño del archivo no puede exceder los 2 MB (2048 KB).',
            'motivo_cambio.required' => 'Es obligatorio registrar la justificación o motivo institucional del cambio.',
            'motivo_cambio.min' => 'El motivo del cambio debe contener al menos 5 caracteres.',
            'motivo_cambio.max' => 'El motivo del cambio no puede exceder los 500 caracteres.',
        ];
    }
}

