<?php

namespace App\Http\Resources;

use App\Http\JsonApi\JsonApiResource;
use App\Models\DocumentEvent;
use Illuminate\Database\Eloquent\Model;

/** @mixin DocumentEvent */
class DocumentEventResource extends JsonApiResource
{
    public function type(): string
    {
        return 'document-events';
    }

    public function id(Model $model): string
    {
        return (string) $model->id;
    }

    public function attributes(Model $model): array
    {
        return [
            'type' => $model->type,
            'description' => $model->description,
            'meta' => $model->meta ?? [],
            'actor_name' => $model->relationLoaded('user') ? $model->user?->name : null,
            'created_at' => $this->isoDate($model->created_at),
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
