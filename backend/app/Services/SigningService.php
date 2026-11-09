<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Support\Signing\CertificateInfo;
use App\Support\Signing\PdfInfo;
use App\Support\Signing\SignedDocument;
use Carbon\CarbonImmutable;
use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Pdf\Reader\ReaderDocument;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\Box;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\ImageBlock;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\SignatureAppearance;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\TextBlock;
use Dskripchenko\PhpPdf\Pdf\Signature\PdfSigner;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\OpenSslSigner;
use Dskripchenko\PhpPdf\Pdf\Signature\Validation\SignatureValidator;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;
use Dskripchenko\PhpPdf\Style\VerticalAlignment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Signs PDF documents with a PKCS#12 (.p12/.pfx) certificate.
 *
 * A visible signature is stamped (uploaded image + the cryptographic
 * signature data) and the CMS signature is appended as an incremental
 * update, so previous signatures keep covering exactly what they signed.
 */
final class SigningService
{
    public function inspectPdf(string $bytes): PdfInfo
    {
        if (! str_starts_with(ltrim($bytes), '%PDF-')) {
            throw DomainException::invalidPdf('El archivo no empieza con la firma %PDF-.');
        }

        try {
            $reader = ReaderDocument::fromBytes($bytes);
            $pages = [];

            foreach ($reader->pages() as $index => $page) {
                $mediaWidth = abs($page->mediaBox[2] - $page->mediaBox[0]);
                $mediaHeight = abs($page->mediaBox[3] - $page->mediaBox[1]);
                $rotation = (($page->rotate % 360) + 360) % 360;

                if ($mediaWidth <= 0.0 || $mediaHeight <= 0.0) {
                    throw DomainException::invalidPdf();
                }

                $quarterTurn = $rotation === 90 || $rotation === 270;

                $pages[] = [
                    'page' => $index + 1,
                    'width' => $quarterTurn ? $mediaHeight : $mediaWidth,
                    'height' => $quarterTurn ? $mediaWidth : $mediaHeight,
                    'media_width' => $mediaWidth,
                    'media_height' => $mediaHeight,
                    'rotation' => $rotation,
                ];
            }

            if ($pages === []) {
                throw DomainException::invalidPdf('El PDF no tiene páginas.');
            }

            // Make sure the incremental-update writer can read this file too.
            PdfSigner::fromBytes($bytes)->signatureFields();
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::debug('PDF rejected', ['reason' => $exception->getMessage()]);

            if ($this->looksEncrypted($exception->getMessage())) {
                throw DomainException::encryptedPdf();
            }

            throw DomainException::invalidPdf();
        }

        return new PdfInfo(count($pages), hash('sha256', $bytes), $pages);
    }

    /**
     * Reads the certificate, checking the password. Expired certificates are
     * refused when signing but only reported when inspecting.
     */
    public function inspectCertificate(string $pkcs12Bytes, string $password, bool $refuseExpired = false): CertificateInfo
    {
        $parts = [];
        $ok = @openssl_pkcs12_read($pkcs12Bytes, $parts, $password);

        if (! $ok || ! isset($parts['cert'], $parts['pkey'])) {
            throw DomainException::invalidCertificate();
        }

        $parsed = @openssl_x509_parse($parts['cert']);
        $fingerprint = @openssl_x509_fingerprint($parts['cert'], 'sha256');

        if ($parsed === false || $fingerprint === false) {
            throw DomainException::invalidCertificate();
        }

        $subject = $parsed['subject'] ?? [];
        $issuer = $parsed['issuer'] ?? [];

        $certificate = new CertificateInfo(
            subject: $this->flattenDistinguishedName($subject),
            commonName: isset($subject['CN']) ? (string) $subject['CN'] : null,
            email: isset($subject['emailAddress'])
                ? (string) $subject['emailAddress']
                : (isset($subject['email']) ? (string) $subject['email'] : null),
            serial: strtoupper($parsed['serialNumberHex'] ?? dechex((int) ($parsed['serialNumber'] ?? 0))),
            issuer: $this->flattenDistinguishedName($issuer),
            fingerprint: $fingerprint,
            validFrom: isset($parsed['validFrom_time_t']) ? CarbonImmutable::createFromTimestampUTC($parsed['validFrom_time_t']) : null,
            validTo: isset($parsed['validTo_time_t']) ? CarbonImmutable::createFromTimestampUTC($parsed['validTo_time_t']) : null,
        );

        if ($refuseExpired && ($certificate->isExpired() || $certificate->isNotYetValid())) {
            throw DomainException::invalidCertificate(expired: true);
        }

        return $certificate;
    }

    /**
     * Signs the PDF and returns the signed bytes.
     *
     * @param  array{page: int|string, x: int|float|string, y: int|float|string, width: int|float|string, height: int|float|string}  $placement
     *         Coordinates are in points, measured from the top-left corner of the
     *         page as displayed (the way the PDF viewport shows it).
     */
    public function sign(
        string $pdfBytes,
        string $pkcs12Bytes,
        string $password,
        string $imageBytes,
        array $placement,
        string $signerName,
        ?string $reason,
        ?string $location,
        string $fieldName,
        int $sequence,
        ?CarbonImmutable $signedAt = null,
    ): SignedDocument {
        $info = $this->inspectPdf($pdfBytes);
        $certificate = $this->inspectCertificate($pkcs12Bytes, $password, refuseExpired: true);
        $rect = $this->toUserSpace($info, $placement);

        try {
            $image = PdfImage::fromBytes($imageBytes);
        } catch (Throwable) {
            throw DomainException::invalidImage();
        }

        $signedAt ??= CarbonImmutable::now();

        $appearance = new SignatureAppearance(
            blocks: [
                new ImageBlock($image, new Box(0.0, 0.0, 0.27, 1.0)),
                new TextBlock(
                    ['FIRMADO DIGITALMENTE', '{signer}'],
                    new Box(0.29, 0.0, 0.71, 0.30),
                    sizePt: 7.0,
                    minSizePt: 4.5,
                    color: '#1F3A93',
                    bold: true,
                    verticalAlign: VerticalAlignment::Center,
                ),
                new TextBlock(
                    [
                        'Certificado: {serial}',
                        'Emisor: {issuer}',
                        'Fecha: {signedAt}',
                        'SHA-256: {certificateFingerprint}',
                    ],
                    new Box(0.29, 0.32, 0.71, 0.68),
                    sizePt: 5.6,
                    minSizePt: 3.4,
                    color: '#333333',
                ),
            ],
            borderColor: '#1F3A93',
            borderWidthPt: 0.8,
            cornerRadiusPt: 3.0,
            backgroundColor: null,
            paddingPt: 4.0,
            dateTimeFormat: 'Y-m-d H:i:s P',
            variables: [
                'signer' => $signerName,
                'certificateFingerprint' => $this->formatFingerprint($certificate->fingerprint),
            ],
        );

        $signer = OpenSslSigner::fromPkcs12($pkcs12Bytes, $password);

        try {
            $signedBytes = PdfSigner::fromBytes($pdfBytes)
                ->addField(
                    $fieldName,
                    page: $rect['page'] - 1,
                    x: $rect['x'],
                    y: $rect['y'],
                    width: $rect['width'],
                    height: $rect['height'],
                    appearance: $appearance,
                )
                ->sign(new SignatureConfig(
                    signer: $signer,
                    signerName: $signerName,
                    reason: $reason,
                    location: $location,
                    signedAt: $signedAt,
                ));
        } catch (Throwable $exception) {
            Log::error('PDF signing failed', ['reason' => $exception->getMessage()]);

            throw DomainException::signingFailed('No se pudo aplicar la firma al documento.');
        }

        $this->assertSignatureIsValid($signedBytes, $fieldName);

        return new SignedDocument(
            bytes: $signedBytes,
            sha256: hash('sha256', $signedBytes),
            certificate: $certificate,
            signedAt: $signedAt,
            fieldName: $fieldName,
            sequence: $sequence,
        );
    }

    /**
     * Verifies every signature of the file, for the verification endpoint.
     *
     * `integrity_ok` means no signed byte was altered; `all_valid` also requires
     * the CMS signature to check out; whether the signer chains to a configured
     * trust root is reported separately in `all_trusted`, so self-signed
     * certificates are not called invalid.
     *
     * @return array{all_valid: bool, all_trusted: bool, integrity_ok: bool, signatures: list<array<string, mixed>>}
     */
    public function verify(string $pdfBytes): array
    {
        $report = SignatureValidator::fromBytes($pdfBytes)->validate($this->trustStore());

        $signatures = [];

        foreach ($report->signatures as $signature) {
            $signatures[] = [
                'field_name' => $signature->fieldName,
                'intact' => $signature->intact,
                'cryptographically_valid' => $signature->cryptographicallyValid,
                'trusted' => $signature->trusted,
                'covers_whole_file' => $signature->coversWholeFile,
                'signer' => $signature->signer?->subjectCommonName(),
                'claimed_signing_time' => $signature->claimedSigningTime?->format(DATE_ATOM),
                'timestamp_time' => $signature->timestampTime?->format(DATE_ATOM),
                'errors' => $signature->errors,
                'warnings' => $signature->warnings,
            ];
        }

        $integrityOk = $signatures !== [] && array_reduce(
            $signatures,
            static fn (bool $carry, array $signature): bool => $carry && $signature['intact'],
            true,
        );

        $allValid = $signatures !== [] && array_reduce(
            $signatures,
            static fn (bool $carry, array $signature): bool => $carry
                && $signature['intact']
                && $signature['cryptographically_valid'],
            true,
        );

        $allTrusted = $signatures !== [] && array_reduce(
            $signatures,
            static fn (bool $carry, array $signature): bool => $carry && $signature['trusted'],
            true,
        );

        return [
            'all_valid' => $allValid,
            'all_trusted' => $allTrusted,
            'integrity_ok' => $integrityOk,
            'signatures' => $signatures,
        ];
    }

    private function assertSignatureIsValid(string $signedBytes, string $fieldName): void
    {
        try {
            $report = SignatureValidator::fromBytes($signedBytes)->validate();
            $signature = $report->signature($fieldName);
        } catch (Throwable $exception) {
            Log::error('Signed PDF could not be verified', ['reason' => $exception->getMessage()]);

            throw DomainException::signingFailed('El documento firmado no pudo verificarse.');
        }

        if ($signature === null || ! $signature->intact || ! $signature->cryptographicallyValid) {
            Log::error('Signed PDF failed integrity verification', [
                'field' => $fieldName,
                'errors' => $signature?->errors ?? [],
            ]);

            throw DomainException::signingFailed('La firma no superó la verificación de integridad.');
        }
    }

    private function trustStore(): ?\Dskripchenko\PhpPdf\Pdf\Signature\Validation\TrustStore
    {
        $path = config('firma.trust_store_path');

        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            return null;
        }

        return \Dskripchenko\PhpPdf\Pdf\Signature\Validation\TrustStore::fromPem((string) file_get_contents($path));
    }

    /**
     * Maps a placement in display space (top-left origin) to PDF user space
     * (bottom-left origin), honouring the page's /Rotate entry.
     *
     * @param  array{page: int|string, x: int|float|string, y: int|float|string, width: int|float|string, height: int|float|string}  $placement
     * @return array{page: int, x: float, y: float, width: float, height: float}
     */
    private function toUserSpace(PdfInfo $info, array $placement): array
    {
        $pageNumber = (int) $placement['page'];
        $x = (float) $placement['x'];
        $y = (float) $placement['y'];
        $width = (float) $placement['width'];
        $height = (float) $placement['height'];

        if ($pageNumber < 1 || $pageNumber > $info->pageCount) {
            throw DomainException::invalidPlacement(
                "La página {$pageNumber} no existe; el documento tiene {$info->pageCount} página(s).",
                'page',
            );
        }

        $page = $info->page($pageNumber);

        $minWidth = (float) config('firma.signature.min_width_pt', 120);
        $minHeight = (float) config('firma.signature.min_height_pt', 45);

        if ($width < $minWidth || $height < $minHeight) {
            throw DomainException::invalidPlacement(
                sprintf('El área de la firma debe medir al menos %s × %s puntos.', $minWidth, $minHeight),
                'width',
            );
        }

        if ($x < 0 || $y < 0 || $x + $width > $page['width'] + 0.5 || $y + $height > $page['height'] + 0.5) {
            throw DomainException::invalidPlacement(
                sprintf(
                    'La firma debe quedar dentro de la página %d (%.0f × %.0f puntos).',
                    $pageNumber,
                    $page['width'],
                    $page['height'],
                ),
                'x',
            );
        }

        $mediaWidth = $page['media_width'];
        $mediaHeight = $page['media_height'];

        [$userX, $userY, $userWidth, $userHeight] = match ($page['rotation']) {
            90 => [$y, $x, $height, $width],
            180 => [$mediaWidth - $x - $width, $mediaHeight - $y - $height, $width, $height],
            270 => [$mediaWidth - $y - $height, $mediaHeight - $x - $width, $height, $width],
            default => [$x, $mediaHeight - $y - $height, $width, $height],
        };

        // Keep the stamp inside the MediaBox even for odd page origins.
        $userX = max(0.0, min($userX, $mediaWidth - $userWidth));
        $userY = max(0.0, min($userY, $mediaHeight - $userHeight));

        return [
            'page' => $pageNumber,
            'x' => round($userX, 2),
            'y' => round($userY, 2),
            'width' => round($userWidth, 2),
            'height' => round($userHeight, 2),
        ];
    }

    /** @param  array<string, mixed>  $subject */
    private function flattenDistinguishedName(array $subject): string
    {
        $parts = [];

        foreach ($subject as $key => $value) {
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }

            $parts[] = $key.'='.$value;
        }

        return implode(', ', $parts);
    }

    private function formatFingerprint(string $fingerprint): string
    {
        $hex = strtoupper(str_replace(':', '', $fingerprint));

        return implode(':', str_split(substr($hex, 0, 16), 2)).'…';
    }

    private function looksEncrypted(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'encrypt') || str_contains($message, 'password');
    }
}
