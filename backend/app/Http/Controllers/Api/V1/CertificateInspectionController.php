<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\JsonApi\RespondsWithJsonApi;
use App\Http\Requests\InspectCertificateRequest;
use App\Services\SigningService;
use Illuminate\Http\JsonResponse;

class CertificateInspectionController extends Controller
{
    use RespondsWithJsonApi;

    public function __invoke(InspectCertificateRequest $request, SigningService $signing): JsonResponse
    {
        $certificate = $signing->inspectCertificate(
            (string) file_get_contents($request->file('certificate')->getRealPath()),
            $request->validated('password'),
        );

        return $this->jsonApiResponse([
            'data' => [
                'type' => 'certificates',
                'id' => $certificate->fingerprint,
                'attributes' => $certificate->toArray(),
            ],
            'meta' => [
                'usable_for_signing' => ! $certificate->isExpired() && ! $certificate->isNotYetValid(),
            ],
        ]);
    }
}
