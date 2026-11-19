<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Selección de tarjetas físicas y receptor de una entrega. El responsable y la fecha no se aceptan del navegador:
 * los fija el servidor. La clave de operación solo se exige al confirmar (la genera el servidor en el resumen).
 */
class RegistrarEntregaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('access-entregas') && $this->user()->can('deliver-credentials');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['receptor_nombre' => preg_replace('/\s+/u', ' ', trim((string) $this->input('receptor_nombre')))]);
    }

    public function rules(): array
    {
        return [
            'receptor_nombre' => ['required', 'string', 'min:3', 'max:150'],
            'impresiones' => ['required', 'array', 'min:1', 'max:500'],
            'impresiones.*' => ['required', 'integer', 'distinct', 'min:1'],
            'clave_operacion' => [$this->routeIs('entregas.entrega.store') ? 'required' : 'prohibited', 'uuid'],
        ];
    }

    public function attributes(): array
    {
        return [
            'receptor_nombre' => 'nombre completo de quien recibe',
            'impresiones' => 'credenciales seleccionadas',
        ];
    }

    public function messages(): array
    {
        return [
            'impresiones.required' => 'Seleccione al menos una credencial para entregar.',
        ];
    }

    /** Ante un error se vuelve al formulario (la página de confirmación es una respuesta a un POST). */
    protected function getRedirectUrl(): string
    {
        return route('entregas.entrega.nueva', $this->route('solicitud'));
    }

    /** @return list<int> */
    public function impresionIds(): array
    {
        return array_map('intval', $this->validated('impresiones'));
    }
}
