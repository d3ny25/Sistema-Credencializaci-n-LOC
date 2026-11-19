<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filtros del listado de Entrega y Seguimiento: solo por número de tarjeta y estado persistido de la solicitud. */
class FiltrarTarjetasEntregaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('access-entregas');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', Rule::in(['recibida', 'evaluada', 'enviada_impresion'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'q.max' => 'El número de tarjeta no puede exceder 100 caracteres.',
            'estado.in' => 'El estado seleccionado no es válido.',
        ];
    }
}
