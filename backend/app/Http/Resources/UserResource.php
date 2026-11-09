<?php

namespace App\Http\Resources;

use App\Http\JsonApi\JsonApiResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** @mixin User */
class UserResource extends JsonApiResource
{
    public function type(): string
    {
        return 'users';
    }

    public function id(Model $model): string
    {
        return (string) $model->id;
    }

    public function attributes(Model $model): array
    {
        return [
            'name' => $model->name,
            'email' => $model->email,
            'created_at' => $this->isoDate($model->created_at),
        ];
    }
}
