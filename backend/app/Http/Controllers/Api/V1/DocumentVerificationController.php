<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\JsonApi\RespondsWithJsonApi;
use App\Models\Document;
use App\Services\SigningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class DocumentVerificationController extends Controller
{
    use RespondsWithJsonApi;

    public function __invoke(Document $document, SigningService $signing): JsonResponse
    {
        $this->authorize('view', $document);

        $disk = Storage::disk((string) config('firma.disk'));

        if (! $disk->exists($document->current_path)) {
            abort(404, 'El archivo del documento no está disponible.');
        }

        $report = $signing->verify((string) $disk->get($document->current_path));

        return $this->jsonApiResponse([
            'data' => [
                'type' => 'document-verifications',
                'id' => $document->uuid,
                'attributes' => [
                    'document_status' => $document->status,
                    'sha256' => $document->current_sha256,
                    'trust_store_configured' => (bool) config('firma.trust_store_path'),
                    ...$report,
                ],
            ],
            'links' => [
                'document' => route('api.v1.documents.show', ['document' => $document->uuid]),
            ],
        ]);
    }
}
