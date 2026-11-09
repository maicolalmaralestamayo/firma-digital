<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\JsonApi\RespondsWithJsonApi;
use App\Http\Requests\DeletionConsentRequest;
use App\Http\Resources\DeletionRequestResource;
use App\Models\Document;
use App\Models\DocumentDeletionRequest;
use App\Services\DeletionWorkflowService;
use Illuminate\Http\JsonResponse;

class DeletionConsentController extends Controller
{
    use RespondsWithJsonApi;

    public function __invoke(
        DeletionConsentRequest $request,
        Document $document,
        string $deletionRequest,
        DeletionWorkflowService $workflow,
    ): JsonResponse {
        $this->authorize('consent', $document);

        /** @var DocumentDeletionRequest $record */
        $record = $document->deletionRequests()->whereKey($deletionRequest)->firstOrFail();

        $record = $workflow->consent(
            $document,
            $record,
            $request->user(),
            $request->validated('decision'),
            $request->validated('comment'),
        );

        $record->load(['document.signatures', 'requestedBy', 'consents.user']);

        $document->refresh();

        return $this->resourceResponse(
            new DeletionRequestResource,
            $record,
            200,
            ['consents', 'requested_by'],
            [
                'document_status' => $document->trashed() ? 'deleted' : $document->status,
                'document_deleted' => $document->trashed(),
            ],
        );
    }
}
