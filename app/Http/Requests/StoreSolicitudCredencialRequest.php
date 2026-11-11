<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\LocCatalogoOrganizacional;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreSolicitudCredencialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'numero_tarjeta' => [
                'prohibited',
            ],
            'consecutivo_tarjeta' => [
                'prohibited',
            ],
            'numero_oficio' => [
                'required',
                'string',
                'max:100',
                'regex:/^[A-Za-z0-9\/\-]+$/',
                'unique:solicitudes,numero_oficio',
            ],
            'fecha_recepcion' => [
                'required',
                'date',
            ],
            'catalogo_organizacional_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    $catalogo = LocCatalogoOrganizacional::vigente()->find($value);
                    if (! $catalogo) {
                        $fail('El área seleccionada no existe, no está activa o no pertenece al catálogo organizacional vigente.');
                    }
                },
            ],
            'area_solicitante_clave' => [
                'nullable',
                'string',
                'max:80',
            ],
            'area_solicitante' => [
                'nullable',
                'string',
                'max:180',
            ],
            'observaciones' => [
                'nullable',
                'string',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'numero_tarjeta.prohibited' => 'El número de tarjeta no puede ser asignado manualmente; el sistema lo genera automáticamente.',
            'consecutivo_tarjeta.prohibited' => 'El consecutivo de tarjeta no puede ser enviado por el cliente; el sistema lo genera automáticamente.',
            'numero_oficio.required' => 'El número de oficio es obligatorio.',
            'numero_oficio.unique' => 'El número de oficio ya se encuentra registrado en otra solicitud.',
            'numero_oficio.regex' => 'El número de oficio solo puede contener letras, números, diagonales y guiones.',
            'numero_oficio.max' => 'El número de oficio no puede exceder 100 caracteres.',
            'fecha_recepcion.required' => 'La fecha de recepción es obligatoria.',
            'fecha_recepcion.date' => 'La fecha de recepción no tiene un formato válido.',
            'catalogo_organizacional_id.integer' => 'El identificador del área organizacional debe ser un número entero.',
            'area_solicitante_clave.max' => 'La clave del área solicitante no puede exceder 80 caracteres.',
            'area_solicitante.max' => 'El área solicitante no puede exceder 180 caracteres.',
        ];
    }
}

