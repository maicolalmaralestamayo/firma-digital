<?php

namespace App\Http\Resources;

use App\Http\JsonApi\JsonApiResource;
use App\Models\DocumentDeletionConsent;
use App\Models\DocumentDeletionRequest;
use Illuminate\Database\Eloquent\Model;

/** @mixin DocumentDeletionRequest */
class DeletionRequestResource extends JsonApiResource
{
    public function type(): string
    {
        return 'deletion-requests';
    }

    public function id(Model $model): string
    {
        return (string) $model->id;
    }

    public function attributes(Model $model): array
    {
        $consents = $model->relationLoaded('consents') ? $model->consents : collect();

        $requiredIds = $model->document !== null
            ? $model->document->signatures()
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all()
            : [];

        $approvedIds = $consents
            ->where('decision', DocumentDeletionConsent::DECISION_APPROVED)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $rejectedIds = $consents
            ->where('decision', DocumentDeletionConsent::DECISION_REJECTED)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return [
            'status' => $model->status,
            'reason' => $model->reason,
            'required_consents' => count($requiredIds),
            'approved_consents' => count(array_intersect($requiredIds, $approvedIds)),
            'rejected_consents' => count(array_intersect($requiredIds, $rejectedIds)),
            'pending_consents' => array_values(array_diff($requiredIds, $approvedIds, $rejectedIds)),
            'created_at' => $this->isoDate($model->created_at),
            'resolved_at' => $this->isoDate($model->resolved_at),
        ];
    }

    public function relationships(Model $model): array
    {
        $consents = $model->relationLoaded('consents')
            ? $this->toMany($model->consents->map(fn ($consent) => [
                'type' => 'deletion-consents',
                'id' => (string) $consent->id,
            ])->values()->all())
            : $this->toMany([]);

        return [
            'document' => $this->toOne('documents', $model->document?->uuid),
            'requested_by' => $this->toOne('users', $model->requested_by),
            'consents' => $consents,
        ];
    }

    public function included(Model $model, array $include): array
    {
        $resources = [];

        if (in_array('consents', $include, true) && $model->relationLoaded('consents')) {
            $resource = new DeletionConsentResource;

            foreach ($model->consents as $consent) {
                $resources[] = $resource->toArray($consent);
            }
        }

        if (in_array('requested_by', $include, true) && $model->relationLoaded('requestedBy') && $model->requestedBy !== null) {
            $resources[] = (new UserResource)->toArray($model->requestedBy);
        }

        return $resources;
    }
}
