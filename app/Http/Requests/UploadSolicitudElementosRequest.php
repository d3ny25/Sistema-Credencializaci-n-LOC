<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadSolicitudElementosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'archivo' => [
                'required',
                'file',
                'mimes:xlsx,xls',
                'max:51200',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'archivo.required' => 'Es obligatorio seleccionar un archivo Excel.',
            'archivo.file' => 'El archivo proporcionado no es válido.',
            'archivo.mimes' => 'El archivo debe tener extensión .xlsx o .xls.',
            'archivo.max' => 'El archivo no puede exceder los 50 MB.',
        ];
    }
}

