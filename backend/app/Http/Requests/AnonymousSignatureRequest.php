<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesSignaturePayload;
use Illuminate\Foundation\Http\FormRequest;

class AnonymousSignatureRequest extends FormRequest
{
    use ValidatesSignaturePayload;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'pdf' => [
                'required',
                'file',
                'extensions:pdf',
                'max:'.(int) config('firma.max_pdf_kb', 20480),
            ],
            'signer_name' => ['nullable', 'string', 'max:190'],
            ...$this->signaturePayloadRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'pdf.required' => 'Sube el PDF que quieres firmar.',
            'pdf.extensions' => 'El documento debe ser un archivo PDF.',
            'pdf.max' => 'El PDF supera el tamaño máximo permitido.',
        ] + $this->signaturePayloadMessages();
    }
}
