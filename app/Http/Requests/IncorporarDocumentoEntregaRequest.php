<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CategoriaDocumentoEntrega;
use App\Services\ExpedienteDocumentalService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Incorporación de un documento al expediente documental de una tarjeta. La tarjeta proviene de la ruta (nunca del
 * formulario); usuario y fecha los fija el servidor. La verificación profunda del contenido ocurre en el servicio.
 */
class IncorporarDocumentoEntregaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('access-entregas') && $this->user()->can('manage-delivery-documents');
    }

    protected function prepareForValidation(): void
    {
        $descripcion = preg_replace('/\s+/u', ' ', trim((string) $this->input('descripcion')));
        $this->merge(['descripcion' => $descripcion === '' ? null : $descripcion]);
    }

    public function rules(): array
    {
        $extensiones = implode(',', array_keys(ExpedienteDocumentalService::FORMATOS));
        $mimes = implode(',', array_unique(array_merge(...array_column(ExpedienteDocumentalService::FORMATOS, 1))));

        return [
            'categoria' => ['required', Rule::enum(CategoriaDocumentoEntrega::class)],
            'descripcion' => ['nullable', 'string', 'max:200', Rule::requiredIf($this->input('categoria') === CategoriaDocumentoEntrega::OTROS->value)],
            // extensions: extensión del nombre; mimetypes: MIME detectado en el servidor (no el declarado).
            'archivo' => ['required', 'file', 'max:'.(int) config('credencializacion.documentos.max_kb'), 'extensions:'.$extensiones, 'mimetypes:'.$mimes],
            'confirmacion' => ['accepted'],
        ];
    }

    public function attributes(): array
    {
        return ['categoria' => 'categoría', 'descripcion' => 'nombre o descripción', 'archivo' => 'archivo', 'confirmacion' => 'confirmación'];
    }

    public function messages(): array
    {
        return [
            'descripcion.required' => 'Para «Otros documentos» indique qué representa el archivo.',
            'archivo.extensions' => 'El archivo no cumple las validaciones: formato no permitido. Use PDF, JPG, PNG, DOCX o XLSX.',
            'archivo.mimetypes' => 'El archivo no cumple las validaciones: su contenido no corresponde a un formato permitido.',
            'archivo.max' => 'El archivo no cumple las validaciones: supera el tamaño máximo de :max KB.',
            'confirmacion.accepted' => 'Confirme que revisó los datos del documento antes de incorporarlo.',
        ];
    }

    public function categoria(): CategoriaDocumentoEntrega
    {
        return CategoriaDocumentoEntrega::from($this->validated('categoria'));
    }
}
