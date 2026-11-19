<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\ConsultaBusquedaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Búsqueda de Consultas. El cliente solo elige texto y contexto (lista cerrada); tablas y columnas las fija el
 * servicio. Mínimo 3 caracteres, o 2 si son dígitos: hay ID_PERSONAL e ID_ARMAMENTO reales de 2 dígitos.
 */
class BuscarConsultaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('access-consultas');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('q')) {
            $this->merge(['q' => preg_replace('/\s+/u', ' ', trim((string) $this->input('q')))]);
        }
    }

    public function rules(): array
    {
        return [
            // sometimes: entrar sin "q" muestra la pantalla inicial; enviarla vacía es un error.
            'q' => ['sometimes', 'required', 'string', 'max:100', 'regex:/^[\pL\pN .\/_-]+$/u', $this->input('q') !== null && ctype_digit((string) $this->input('q')) ? 'min:2' : 'min:3'],
            'tipo' => ['nullable', Rule::in(array_keys(ConsultaBusquedaService::CONTEXTOS))],
        ];
    }

    public function messages(): array
    {
        return [
            'q.required' => 'Escriba un identificador o nombre para buscar.',
            'q.min' => 'Escriba al menos :min caracteres (2 solo para identificadores numéricos).',
            'q.max' => 'La búsqueda admite hasta :max caracteres.',
            'q.regex' => 'La búsqueda solo admite letras, números, espacios y los signos / - . _',
            'tipo.in' => 'Seleccione un contexto de búsqueda válido.',
        ];
    }

    /** Sin "q" válida no se consulta nada: se redirige a la pantalla inicial con el error. */
    protected function getRedirectUrl(): string
    {
        return route('consultas');
    }
}
