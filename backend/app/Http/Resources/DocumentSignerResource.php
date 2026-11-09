<?php

namespace App\Http\Resources;

use App\Http\JsonApi\JsonApiResource;
use App\Models\DocumentSigner;
use Illuminate\Database\Eloquent\Model;

/** @mixin DocumentSigner */
class DocumentSignerResource extends JsonApiResource
{
    public function type(): string
    {
        return 'document-signers';
    }

    public function id(Model $model): string
    {
        return (string) $model->id;
    }

    public function attributes(Model $model): array
    {
        return [
            'email' => $model->email,
            'position' => $model->position,
            'status' => $model->isSigned() ? 'signed' : 'pending',
            'claimed' => $model->isClaimed(),
            'invited_at' => $this->isoDate($model->invited_at),
            'signed_at' => $this->isoDate($model->signed_at),
            'user_name' => $model->relationLoaded('user') ? $model->user?->name : null,
        ];
    }

    public function relationships(Model $model): array
    {
        return [
            'document' => $this->toOne('documents', $model->document?->uuid),
            'user' => $this->toOne('users', $model->user_id),
        ];
    }
}
