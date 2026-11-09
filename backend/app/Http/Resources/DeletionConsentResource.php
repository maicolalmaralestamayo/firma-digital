<?php

namespace App\Http\Resources;

use App\Http\JsonApi\JsonApiResource;
use App\Models\DocumentDeletionConsent;
use Illuminate\Database\Eloquent\Model;

/** @mixin DocumentDeletionConsent */
class DeletionConsentResource extends JsonApiResource
{
    public function type(): string
    {
        return 'deletion-consents';
    }

    public function id(Model $model): string
    {
        return (string) $model->id;
    }

    public function attributes(Model $model): array
    {
        return [
            'decision' => $model->decision,
            'comment' => $model->comment,
            'decided_at' => $this->isoDate($model->decided_at),
            'user_name' => $model->relationLoaded('user') ? $model->user?->name : null,
            'user_email' => $model->relationLoaded('user') ? $model->user?->email : null,
        ];
    }

    public function relationships(Model $model): array
    {
        return [
            'deletion_request' => $this->toOne('deletion-requests', $model->deletion_request_id),
            'user' => $this->toOne('users', $model->user_id),
        ];
    }
}
