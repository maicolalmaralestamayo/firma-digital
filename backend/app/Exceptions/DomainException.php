<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A business rule failed. Rendered as a JSON:API error object with a stable
 * `code` so clients can branch on it.
 */
class DomainException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly string $title,
        string $detail,
        public readonly array $meta = [],
        public readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($detail, 0, $previous);
    }

    /** @param  array<string, mixed>  $meta */
    public static function invalidPdf(?string $detail = null): self
    {
        return new self(
            422,
            'invalid_pdf',
            'El archivo no es un PDF válido',
            $detail ?? 'No se pudo leer el PDF. Verifica que no esté dañado ni protegido con contraseña.',
        );
    }

    public static function encryptedPdf(): self
    {
        return new self(
            422,
            'encrypted_pdf',
            'El PDF está protegido con contraseña',
            'Los PDF cifrados con contraseña no pueden firmarse. Quita la protección antes de subirlo.',
        );
    }

    public static function invalidCertificate(bool $expired = false): self
    {
        return $expired
            ? new self(
                422,
                'certificate_expired',
                'El certificado está vencido',
                'El certificado .p12 está fuera de su período de validez y no se puede usar para firmar.',
            )
            : new self(
                422,
                'invalid_certificate',
                'No se pudo abrir el certificado',
                'La contraseña no es correcta o el archivo .p12/.pfx no contiene un certificado válido.',
            );
    }

    public static function invalidImage(): self
    {
        return new self(
            422,
            'invalid_image',
            'Imagen no válida',
            'La imagen de la firma debe ser PNG o JPEG.',
        );
    }

    public static function invalidPlacement(string $detail, string $pointer = 'page'): self
    {
        return new self(
            422,
            'invalid_placement',
            'Posición de la firma no válida',
            $detail,
            ['pointer' => '/data/attributes/'.$pointer],
        );
    }

    public static function documentSealed(): self
    {
        return new self(
            409,
            'document_sealed',
            'El documento está cerrado',
            'Todos los firmantes ya firmaron y el documento quedó cerrado. Ya no admite nuevas firmas.',
        );
    }

    public static function notASigner(): self
    {
        return new self(
            403,
            'not_a_signer',
            'No eres firmante de este documento',
            'Solo los firmantes invitados pueden firmar este documento.',
        );
    }

    public static function alreadySigned(): self
    {
        return new self(
            409,
            'already_signed',
            'Ya firmaste este documento',
            'Cada firmante puede firmar una sola vez.',
        );
    }

    public static function documentHasSignatures(): self
    {
        return new self(
            409,
            'document_has_signatures',
            'El documento tiene firmas',
            'Un documento firmado solo puede eliminarse con el consentimiento de todos los firmantes. Crea una solicitud de eliminación.',
            ['deletion_request_endpoint' => 'POST /api/v1/documents/{document}/deletion-requests'],
        );
    }

    /** @param  array<string, mixed>  $meta */
    public static function deletionAlreadyPending(array $meta = []): self
    {
        return new self(
            409,
            'deletion_already_pending',
            'Ya existe una solicitud de eliminación',
            'Hay una solicitud de eliminación en curso para este documento.',
            $meta,
        );
    }

    public static function deletionNotPending(): self
    {
        return new self(
            409,
            'deletion_not_pending',
            'La solicitud no está en curso',
            'La solicitud de eliminación ya fue resuelta o cancelada.',
        );
    }

    public static function noSignaturesToConsent(): self
    {
        return new self(
            409,
            'no_signatures_to_consent',
            'El documento no tiene firmas',
            'Un documento sin firmas puede eliminarlo su creador directamente con DELETE /documents/{document}.',
        );
    }

    public static function notAConsentingSigner(): self
    {
        return new self(
            403,
            'not_a_signing_party',
            'No participaste de la firma',
            'Solo quienes firmaron el documento deben dar su consentimiento para eliminarlo.',
        );
    }

    public static function signedDocumentNeedsConsent(): self
    {
        return new self(
            403,
            'consent_required',
            'Se requiere consentimiento',
            'El documento tiene firmas: solo puede eliminarse cuando todos los firmantes lo aprueben.',
        );
    }

    public static function signingFailed(string $detail): self
    {
        return new self(
            500,
            'signing_failed',
            'No se pudo completar la firma',
            $detail,
        );
    }
}
