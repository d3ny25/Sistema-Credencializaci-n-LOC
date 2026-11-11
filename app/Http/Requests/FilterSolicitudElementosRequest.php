<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\MotivoDescarte;
use Illuminate\Foundation\Http\FormRequest;

class FilterSolicitudElementosRequest extends FormRequest
{
    /**
     * Determina si el usuario está autorizado a realizar esta petición.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Reglas de validación para los filtros de elementos de credencialización.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prefiltro' => [
                'nullable',
                'string',
                'in:todos,aptos,descartados,sin_evaluar,Apto,Descartado,Sin evaluar,Todos',
            ],
            'motivo' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $val = trim((string) $value);
                    if ($val === '' || $val === 'todos') {
                        return;
                    }
                    if ($val === 'historico') {
                        return;
                    }
                    if (! MotivoDescarte::isValid($val)) {
                        $fail("El código de motivo de descarte '{$val}' no es válido.");
                    }
                },
            ],
            'agrupamiento' => [
                'nullable',
                'string',
                'in:todos,verificado,sin_clave,discrepante,sin_informacion,ambiguo,Todos,Verificado,Sin clave,Discrepante,Sin información,Ambiguo',
            ],
            'clave' => [
                'nullable',
                'string',
                'max:50',
            ],
            'q' => [
                'nullable',
                'string',
                'max:100',
            ],
            'arma_corta' => [
                'nullable',
                'string',
                'in:todos,con_arma,sin_arma,vinculada,no_vinculada,solicitada,Todos',
            ],
            'arma_larga' => [
                'nullable',
                'string',
                'in:todos,con_arma,sin_arma,vinculada,no_vinculada,solicitada,Todos',
            ],
        ];
    }

    /**
     * Mensajes descriptivos en caso de fallo de validación.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prefiltro.in' => 'El filtro de prefiltro seleccionado no es válido.',
            'agrupamiento.in' => 'El estado de agrupamiento seleccionado no es válido.',
            'clave.max' => 'La clave de catálogo no puede exceder los 50 caracteres.',
            'q.max' => 'El término de búsqueda no puede exceder los 100 caracteres.',
            'arma_corta.in' => 'El filtro de arma corta seleccionado no es válido.',
            'arma_larga.in' => 'El filtro de arma larga seleccionado no es válido.',
        ];
    }

    /**
     * Retorna los filtros saneados y normalizados que tienen valor real.
     *
     * @return array<string, string>
     */
    public function activeFilters(): array
    {
        $validated = $this->validator ? $this->validated() : $this->all();
        $active = [];

        foreach ($validated as $key => $value) {
            if ($value === null) {
                continue;
            }
            $val = trim((string) $value);
            if ($val === '' || $val === 'todos' || $val === 'Todos') {
                continue;
            }
            $active[$key] = $val;
        }

        return $active;
    }
}

