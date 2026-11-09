<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\JsonApi\RespondsWithJsonApi;
use App\Http\Requests\SignDocumentRequest;
use App\Http\Resources\SignatureResource;
use App\Models\Document;
use App\Services\DocumentWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentSignatureController extends Controller
{
    use RespondsWithJsonApi;

    public function index(Request $request, Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $query = $document->signatures()->with(['user', 'document']);

        $paginator = $query->paginate(
            $this->pageSize($request),
            ['*'],
            'page',
            $this->pageNumber($request),
        );

        return $this->collectionResponse(new SignatureResource, $paginator, $request);
    }

    public function store(SignDocumentRequest $request, Document $document, DocumentWorkflowService $workflow): JsonResponse
    {
        if ($document->signerFor($request->user()) === null) {
            throw DomainException::notASigner();
        }

        $data = $request->validated();

        $signature = $workflow->sign(
            document: $document,
            user: $request->user(),
            pkcs12Bytes: (string) file_get_contents($request->file('certificate')->getRealPath()),
            password: $data['password'],
            imageBytes: (string) file_get_contents($request->file('image')->getRealPath()),
            placement: [
                'page' => $data['page'],
                'x' => $data['x'],
                'y' => $data['y'],
                'width' => $data['width'],
                'height' => $data['height'],
            ],
            meta: [
                'reason' => $data['reason'] ?? (string) config('firma.signature.reason'),
                'location' => $data['location'] ?? config('firma.signature.location'),
                'signer_name' => $data['signer_name'] ?? null,
            ],
        );

        $document = $signature->document->fresh(['signers', 'signatures']);

        return $this->resourceResponse(
            new SignatureResource,
            $signature,
            201,
            ['user'],
            [
                'document_status' => $document->status,
                'sealed' => $document->isSealed(),
                'signatures_required' => $document->signers_required,
                'signature_count' => $document->signature_count,
                'remaining_signatures' => $document->remainingSignatures(),
                'current_sha256' => $document->current_sha256,
            ],
            [
                'document' => route('api.v1.documents.show', ['document' => $document->uuid]),
            ],
        );
    }
}
