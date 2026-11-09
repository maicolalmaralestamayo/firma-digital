<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\JsonApi\RespondsWithJsonApi;
use App\Http\Requests\AnonymousSignatureRequest;
use App\Services\SigningService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * The anonymous flow never persists anything: the PDF, certificate, image and
 * password are processed in memory and the signed file is returned in the
 * same response.
 */
class AnonymousSignatureController extends Controller
{
    use RespondsWithJsonApi;

    public function __invoke(AnonymousSignatureRequest $request, SigningService $signing): Response
    {
        $data = $request->validated();

        $pdfBytes = (string) file_get_contents($request->file('pdf')->getRealPath());
        $certificateBytes = (string) file_get_contents($request->file('certificate')->getRealPath());
        $imageBytes = (string) file_get_contents($request->file('image')->getRealPath());

        $certificate = $signing->inspectCertificate($certificateBytes, $data['password']);

        $signerName = trim((string) ($data['signer_name'] ?? '')) ?: $certificate->displayName();

        $signed = $signing->sign(
            pdfBytes: $pdfBytes,
            pkcs12Bytes: $certificateBytes,
            password: $data['password'],
            imageBytes: $imageBytes,
            placement: [
                'page' => $data['page'],
                'x' => $data['x'],
                'y' => $data['y'],
                'width' => $data['width'],
                'height' => $data['height'],
            ],
            signerName: $signerName,
            reason: $data['reason'] ?? (string) config('firma.signature.reason'),
            location: $data['location'] ?? config('firma.signature.location'),
            fieldName: 'FirmaDigital1',
            sequence: 1,
            signedAt: CarbonImmutable::now(),
        );

        $filename = 'documento-firmado-'.now()->format('Ymd-His').'.pdf';

        return response($signed->bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($signed->bytes),
            'Cache-Control' => 'no-store, private',
            'X-Signature-Sha256' => $signed->sha256,
            'X-Certificate-Cn' => Str::ascii($certificate->displayName()),
            'X-Certificate-Serial' => $certificate->serial,
            'X-Signed-At' => $signed->signedAt->toIso8601String(),
        ]);
    }
}
