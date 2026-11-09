<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\JsonApi\RespondsWithJsonApi;
use App\Http\Requests\DeletionRequestStoreRequest;
use App\Http\Resources\DeletionRequestResource;
use App\Models\Document;
use App\Services\DeletionWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeletionRequestController extends Controller
{
    use RespondsWithJsonApi;

    public function store(
        DeletionRequestStoreRequest $request,
        Document $document,
        DeletionWorkflowService $workflow,
    ): JsonResponse {
        $this->authorize('requestDeletion', $document);

        $deletionRequest = $workflow->request($document, $request->user(), $request->validated('reason'));

        $deletionRequest->load(['document.signatures', 'requestedBy', 'consents.user']);

        return $this->resourceResponse(
            new DeletionRequestResource,
            $deletionRequest,
            201,
            ['consents', 'requested_by'],
            ['document_status' => $deletionRequest->document?->status],
            [
                'self' => route('api.v1.documents.deletion-requests.show', [
                    'document' => $document->uuid,
                    'deletionRequest' => $deletionRequest->id,
                ]),
            ],
        );
    }

    public function show(Request $request, Document $document, string $deletionRequest): JsonResponse
    {
        $this->authorize('view', $document);

        $record = $document->deletionRequests()
            ->whereKey($deletionRequest)
            ->firstOrFail();

        $record->load(['document.signatures', 'requestedBy', 'consents.user']);

        return $this->resourceResponse(
            new DeletionRequestResource,
            $record,
            200,
            $this->requestedIncludes($request, ['consents', 'requested_by']),
            ['document_status' => $document->status],
        );
    }
}
