<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\JsonApi\RespondsWithJsonApi;
use App\Http\Resources\DocumentEventResource;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentEventController extends Controller
{
    use RespondsWithJsonApi;

    public function __invoke(Request $request, Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $paginator = $document->events()
            ->with('user')
            ->paginate($this->pageSize($request, 25), ['*'], 'page', $this->pageNumber($request));

        return $this->collectionResponse(new DocumentEventResource, $paginator, $request, ['user']);
    }
}
