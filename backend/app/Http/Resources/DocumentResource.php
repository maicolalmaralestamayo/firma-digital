<?php

namespace App\Http\Resources;

use App\Http\JsonApi\JsonApiResource;
use App\Models\Document;
use App\Models\DocumentDeletionRequest;
use Illuminate\Database\Eloquent\Model;

/** @mixin Document */
class DocumentResource extends JsonApiResource
{
    public const INCLUDES = ['owner', 'signers', 'signatures', 'deletion_requests'];

    public function type(): string
    {
        return 'documents';
    }

    public function id(Model $model): string
    {
        return $model->uuid;
    }

    public function attributes(Model $model): array
    {
        $user = request()->user();
        $slot = $this->slotFor($model, $user?->id);
        $isOwner = $user !== null && $model->owner_id === $user->id;
        $isParticipant = $isOwner || $slot !== null;

        $pendingRequest = $model->relationLoaded('deletionRequests')
            ? $model->deletionRequests->firstWhere('status', DocumentDeletionRequest::STATUS_PENDING)
            : null;

        $signedByMe = $slot !== null && $slot->isSigned();

        return [
            'title' => $model->title,
            'status' => $model->status,
            'sealed' => $model->isSealed(),
            'signers_required' => $model->signers_required,
            'signature_count' => $model->signature_count,
            'remaining_signatures' => $model->remainingSignatures(),
            'page_count' => $model->page_count,
            'original_sha256' => $model->original_sha256,
            'current_sha256' => $model->current_sha256,
            'sealed_at' => $this->isoDate($model->sealed_at),
            'created_at' => $this->isoDate($model->created_at),
            'updated_at' => $this->isoDate($model->updated_at),
            'my_role' => $isOwner
                ? ($slot !== null ? 'owner_and_signer' : 'owner')
                : ($slot !== null ? 'signer' : 'observer'),
            'my_signature_status' => $slot === null ? null : ($signedByMe ? 'signed' : 'pending'),
            'permissions' => [
                'can_sign' => $model->isOpen() && $slot !== null && ! $signedByMe,
                'can_download' => $isParticipant,
                'can_delete' => $isOwner && $model->signature_count === 0,
                'can_request_deletion' => $isParticipant && $pendingRequest === null && $model->signature_count > 0,
                'can_consent_deletion' => $pendingRequest !== null && $signedByMe,
            ],
        ];
    }

    public function relationships(Model $model): array
    {
        $signers = $model->relationLoaded('signers')
            ? $this->toMany($model->signers->map(fn ($signer) => [
                'type' => 'document-signers',
                'id' => (string) $signer->id,
            ])->values()->all())
            : $this->toMany([], ['related' => $this->relatedUrl($model, 'signers')]);

        $signatures = $model->relationLoaded('signatures')
            ? $this->toMany($model->signatures->map(fn ($signature) => [
                'type' => 'signatures',
                'id' => (string) $signature->id,
            ])->values()->all())
            : $this->toMany([], ['related' => $this->relatedUrl($model, 'signatures')]);

        return [
            'owner' => $this->toOne('users', $model->owner_id),
            'signers' => $signers,
            'signatures' => $signatures,
        ];
    }

    public function links(Model $model): array
    {
        return [
            'self' => route('api.v1.documents.show', ['document' => $model->uuid]),
            'file' => route('api.v1.documents.file', ['document' => $model->uuid]),
            'verification' => route('api.v1.documents.verification', ['document' => $model->uuid]),
        ];
    }

    public function included(Model $model, array $include): array
    {
        $resources = [];

        if (in_array('owner', $include, true) && $model->relationLoaded('owner') && $model->owner !== null) {
            $resources[] = (new UserResource)->toArray($model->owner);
        }

        if (in_array('signers', $include, true) && $model->relationLoaded('signers')) {
            $resource = new DocumentSignerResource;

            foreach ($model->signers as $signer) {
                $resources[] = $resource->toArray($signer);
            }
        }

        if (in_array('signatures', $include, true) && $model->relationLoaded('signatures')) {
            $resource = new SignatureResource;

            foreach ($model->signatures as $signature) {
                $resources[] = $resource->toArray($signature);
            }
        }

        if (in_array('deletion_requests', $include, true) && $model->relationLoaded('deletionRequests')) {
            $resource = new DeletionRequestResource;

            foreach ($model->deletionRequests as $request) {
                $resources[] = $resource->toArray($request);
            }
        }

        return $resources;
    }

    private function slotFor(Document $document, ?int $userId): mixed
    {
        if ($userId === null) {
            return null;
        }

        if ($document->relationLoaded('signers')) {
            return $document->signers->firstWhere('user_id', $userId);
        }

        return $document->signers()->where('user_id', $userId)->first();
    }

    private function relatedUrl(Document $document, string $relation): string
    {
        return route('api.v1.documents.show', ['document' => $document->uuid]).'?include='.$relation;
    }
}
