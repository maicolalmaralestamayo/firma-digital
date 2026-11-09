<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\JsonApi\RespondsWithJsonApi;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Services\DocumentWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DocumentController extends Controller
{
    use RespondsWithJsonApi;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Document::query()
            ->visibleTo($user)
            ->with([
                'owner',
                'signers.user',
                'deletionRequests' => fn ($query) => $query->where('status', 'pending'),
            ]);

        $status = $request->query('filter.status');

        if ($status !== null) {
            if (! in_array($status, [Document::STATUS_OPEN, Document::STATUS_SEALED], true)) {
                throw new DomainException(
                    400,
                    'invalid_filter',
                    'Filtro no válido',
                    'filter[status] solo admite "open" o "sealed".',
                    ['pointer' => 'filter.status'],
                );
            }

            $query->where('status', $status);
        }

        $role = $request->query('filter.role', 'all');

        if (! in_array($role, ['all', 'owner', 'signer'], true)) {
            throw new DomainException(
                400,
                'invalid_filter',
                'Filtro no válido',
                'filter[role] solo admite "all", "owner" o "signer".',
                ['pointer' => 'filter.role'],
            );
        }

        if ($role === 'owner') {
            $query->ownedBy($user);
        } elseif ($role === 'signer') {
            $query->sharedWith($user);
        }

        $search = $request->query('filter.search');

        if (is_string($search) && trim($search) !== '') {
            $query->whereRaw('title ILIKE ?', ['%'.trim($search).'%']);
        }

        $sort = $this->requestedSort($request, ['created_at', 'updated_at', 'title', 'status', 'sealed_at']);

        $column = ltrim($sort, '-');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        $query->orderBy($column, $direction);

        $includes = $this->requestedIncludes($request, DocumentResource::INCLUDES);

        if (in_array('signatures', $includes, true)) {
            $query->with(['signatures.user']);
        }

        if (in_array('deletion_requests', $includes, true)) {
            $query->with(['deletionRequests.consents.user', 'deletionRequests.requestedBy']);
        }

        $paginator = $query->paginate(
            $this->pageSize($request),
            ['*'],
            'page',
            $this->pageNumber($request),
        );

        return $this->collectionResponse(new DocumentResource, $paginator, $request, $includes);
    }

    public function store(StoreDocumentRequest $request, DocumentWorkflowService $workflow): JsonResponse
    {
        $document = $workflow->create(
            $request->user(),
            $request->safe()->only(['title', 'signers_required', 'signers']),
            $request->file('file'),
            $request->validated('reason'),
        );

        return $this->resourceResponse(
            new DocumentResource,
            $document,
            201,
            DocumentResource::INCLUDES,
            [
                'original_sha256' => $document->original_sha256,
                'page_count' => $document->page_count,
            ],
            [
                'self' => route('api.v1.documents.show', ['document' => $document->uuid]),
            ],
        );
    }

    public function show(Request $request, Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $document->load([
            'owner',
            'signers.user',
            'signatures.user',
            'deletionRequests.consents.user',
            'deletionRequests.requestedBy',
        ]);

        return $this->resourceResponse(
            new DocumentResource,
            $document,
            200,
            $this->requestedIncludes($request, DocumentResource::INCLUDES),
            [
                'current_sha256' => $document->current_sha256,
                'pending_deletion_request_id' => $document->deletionRequests
                    ->firstWhere('status', 'pending')?->id,
            ],
        );
    }

    public function destroy(Request $request, Document $document, DocumentWorkflowService $workflow): Response
    {
        $this->authorize('delete', $document);

        if ($document->signature_count > 0 || $document->signatures()->exists()) {
            throw DomainException::documentHasSignatures();
        }

        $workflow->deleteUnsigned($document, $request->user());

        return response()->noContent();
    }
}
