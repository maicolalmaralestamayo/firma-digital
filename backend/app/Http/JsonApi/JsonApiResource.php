<?php

namespace App\Http\JsonApi;

use Illuminate\Database\Eloquent\Model;

abstract class JsonApiResource
{
    abstract public function type(): string;

    abstract public function id(Model $model): string;

    /** @return array<string, mixed> */
    abstract public function attributes(Model $model): array;

    /**
     * Relationship objects, e.g. ['owner' => ['data' => [...], 'links' => [...]]].
     *
     * @return array<string, array<string, mixed>>
     */
    public function relationships(Model $model): array
    {
        return [];
    }

    /** @return array<string, string> */
    public function links(Model $model): array
    {
        return [];
    }

    /**
     * Related resources requested through `?include=`.
     *
     * @param  list<string>  $include
     * @return list<array<string, mixed>>
     */
    public function included(Model $model, array $include): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function toArray(Model $model): array
    {
        $resource = [
            'type' => $this->type(),
            'id' => $this->id($model),
        ];

        $attributes = $this->attributes($model);

        if ($attributes !== []) {
            $resource['attributes'] = $attributes;
        }

        $relationships = $this->relationships($model);

        if ($relationships !== []) {
            $resource['relationships'] = $relationships;
        }

        $links = $this->links($model);

        if ($links !== []) {
            $resource['links'] = $links;
        }

        return $resource;
    }

    /**
     * Builds a to-one relationship object.
     *
     * @param  array<string, string>  $links
     * @return array<string, mixed>
     */
    protected function toOne(string $type, int|string|null $id, array $links = []): array
    {
        $relationship = ['data' => $id === null ? null : ['type' => $type, 'id' => (string) $id]];

        if ($links !== []) {
            $relationship['links'] = $links;
        }

        return $relationship;
    }

    /**
     * Builds a to-many relationship object.
     *
     * @param  list<array{type: string, id: string}>  $identifiers
     * @param  array<string, string>  $links
     * @return array<string, mixed>
     */
    protected function toMany(array $identifiers, array $links = []): array
    {
        $relationship = ['data' => array_values($identifiers)];

        if ($links !== []) {
            $relationship['links'] = $links;
        }

        return $relationship;
    }

    protected function isoDate(?object $date): ?string
    {
        return $date?->toIso8601String();
    }
}
