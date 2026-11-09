<?php

namespace App\Http\Requests\Concerns;

trait ValidatesSignaturePayload
{
    /**
     * Rules for the certificate, the image and the stamp placement.
     *
     * Placement is expressed in PDF points measured from the top-left corner
     * of the page as displayed, matching what the user draws on the preview.
     *
     * @return array<string, mixed>
     */
    protected function signaturePayloadRules(): array
    {
        return [
            'certificate' => [
                'required',
                'file',
                'extensions:p12,pfx',
                'max:'.(int) config('firma.max_certificate_kb', 512),
            ],
            'password' => ['required', 'string', 'max:255'],
            'image' => [
                'required',
                'file',
                'mimetypes:image/png,image/jpeg',
                'max:'.(int) config('firma.max_image_kb', 4096),
            ],
            'page' => ['required', 'integer', 'min:1'],
            'x' => ['required', 'numeric'],
            'y' => ['required', 'numeric'],
            'width' => ['required', 'numeric', 'min:1'],
            'height' => ['required', 'numeric', 'min:1'],
            'reason' => ['nullable', 'string', 'max:190'],
            'location' => ['nullable', 'string', 'max:190'],
        ];
    }

    /** @return array<string, string> */
    protected function signaturePayloadMessages(): array
    {
        return [
            'certificate.required' => 'Sube el certificado .p12 o .pfx.',
            'certificate.extensions' => 'El certificado debe ser un archivo .p12 o .pfx.',
            'certificate.max' => 'El certificado supera el tamaño máximo permitido.',
            'password.required' => 'Ingresa la contraseña del certificado.',
            'image.required' => 'Sube la imagen que se estampará en el documento.',
            'image.mimetypes' => 'La imagen debe ser PNG o JPEG.',
            'image.max' => 'La imagen supera el tamaño máximo permitido.',
            'page.required' => 'Indica la página donde se firmará.',
            'page.min' => 'La página debe ser 1 o mayor.',
            'x.required' => 'Indica la posición horizontal de la firma.',
            'y.required' => 'Indica la posición vertical de la firma.',
            'width.required' => 'Indica el ancho del área de firma.',
            'height.required' => 'Indica el alto del área de firma.',
        ];
    }
}
