<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $signers = $this->input('signers');

        if (is_array($signers)) {
            $this->merge([
                'signers' => array_values(array_map(
                    static fn ($email) => is_string($email) ? strtolower(trim($email)) : $email,
                    $signers,
                )),
            ]);
        }

        $this->merge([
            'title' => is_string($this->input('title')) ? trim($this->input('title')) : $this->input('title'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $min = (int) config('firma.min_signers', 1);
        $max = (int) config('firma.max_signers', 20);

        return [
            'file' => [
                'required',
                'file',
                'extensions:pdf',
                'max:'.(int) config('firma.max_pdf_kb', 20480),
            ],
            'title' => ['required', 'string', 'max:180'],
            'signers_required' => ['required', 'integer', "min:{$min}", "max:{$max}"],
            'signers' => ['required', 'array', 'min:'.$min],
            'signers.*' => ['required', 'string', 'email:rfc', 'max:190', 'distinct'],
            'reason' => ['nullable', 'string', 'max:190'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'Sube el PDF que se firmará.',
            'file.extensions' => 'El documento debe ser un archivo PDF.',
            'file.max' => 'El PDF supera el tamaño máximo permitido.',
            'title.required' => 'Indica un título para el documento.',
            'signers_required.required' => 'Indica cuántos firmantes se requieren.',
            'signers_required.min' => 'Se requiere al menos un firmante.',
            'signers_required.max' => 'Se superó el máximo de firmantes permitido.',
            'signers.required' => 'Invita a los firmantes por correo electrónico.',
            'signers.*.email' => 'Uno de los correos de firmante no es válido.',
            'signers.*.distinct' => 'No puedes invitar dos veces al mismo correo.',
        ];
    }
}
