<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesSignaturePayload;
use Illuminate\Foundation\Http\FormRequest;

class SignDocumentRequest extends FormRequest
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
            ...$this->signaturePayloadRules(),
            'signer_name' => ['nullable', 'string', 'max:190'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->signaturePayloadMessages();
    }
}
