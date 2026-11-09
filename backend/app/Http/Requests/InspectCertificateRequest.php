<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InspectCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'certificate' => [
                'required',
                'file',
                'extensions:p12,pfx',
                'max:'.(int) config('firma.max_certificate_kb', 512),
            ],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'certificate.required' => 'Sube el certificado .p12 o .pfx.',
            'certificate.extensions' => 'El certificado debe ser un archivo .p12 o .pfx.',
            'password.required' => 'Ingresa la contraseña del certificado.',
        ];
    }
}
