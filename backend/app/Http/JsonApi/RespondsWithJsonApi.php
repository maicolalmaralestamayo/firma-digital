<?php

namespace App\Http\JsonApi;

use App\Exceptions\DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait RespondsWithJsonApi
{
    /** @param  array<string, mixed>  $payload */
    protected function jsonApiResponse(array $payload, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse(
            $payload,
            $status,
            ['Content-Type' => 'application/vnd.api+json'] + $headers,
        );
    }

    /**
     * @param  list<string>  $include
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $links
     */
    protected function resourceResponse(
        JsonApiResource $resource,
        Model $model,
        int $status = 200,
        array $include = [],
        array $meta = [],
        array $links = [],
    ): JsonResponse {
        $payload = ['data' => $resource->toArray($model)];

        $included = $resource->included($model, $include);

        if ($included !== []) {
            $payload['included'] = $this->uniqueResources($included);
        }

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        if ($links !== []) {
            $payload['links'] = $links;
        }

        return $this->jsonApiResponse($payload, $status);
    }

    /**
     * @param  list<string>  $include
     * @param  array<string, mixed>  $meta
     */
    protected function collectionResponse(
        JsonApiResource $resource,
        LengthAwarePaginator $paginator,
        Request $request,
        array $include = [],
        array $meta = [],
    ): JsonResponse {
        $items = method_exists($paginator, 'getCollection')
            ? $paginator->getCollection()
            : collect($paginator->items());

        $included = [];

        foreach ($items as $model) {
            array_push($included, ...$resource->included($model, $include));
        }

        $payload = [
            'data' => $items->map(fn (Model $model) => $resource->toArray($model))->values()->all(),
            'meta' => $meta + [
                'pagination' => [
                    'page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ];

        if ($included !== []) {
            $payload['included'] = $this->uniqueResources($included);
        }

        $payload['links'] = $this->paginationLinks($request, $paginator);

        return $this->jsonApiResponse($payload);
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    protected function requestedIncludes(Request $request, array $allowed): array
    {
        $raw = $request->query('include');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $requested = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));

        $unknown = array_diff($requested, $allowed);

        if ($unknown !== []) {
            throw new DomainException(
                400,
                'unsupported_include',
                'Include no soportado',
                'Incluye solo relaciones soportadas: '.implode(', ', $allowed).'.',
                ['requested' => array_values($unknown)],
            );
        }

        return $requested;
    }

    /** @param  list<string>  $allowed */
    protected function requestedSort(Request $request, array $allowed, string $default = '-created_at'): string
    {
        $raw = $request->query('sort');

        if (! is_string($raw) || trim($raw) === '') {
            return $default;
        }

        $field = ltrim($raw, '-+');

        if (! in_array($field, $allowed, true)) {
            throw new DomainException(
                400,
                'unsupported_sort',
                'Orden no soportado',
                'Ordena por uno de: '.implode(', ', $allowed).'.',
                ['requested' => $raw],
            );
        }

        $descending = str_starts_with($raw, '-');

        return ($descending ? '-' : '').$field;
    }

    protected function pageSize(Request $request, int $default = 15, int $max = 50): int
    {
        $size = $request->query('page.size', $default);

        return max(1, min((int) $size, $max));
    }

    protected function pageNumber(Request $request): int
    {
        return max(1, (int) $request->query('page.number', 1));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function errorResponse(
        int $status,
        string $code,
        string $title,
        string $detail,
        array $meta = [],
    ): JsonResponse {
        $error = [
            'status' => (string) $status,
            'code' => $code,
            'title' => $title,
            'detail' => $detail,
        ];

        if ($meta !== []) {
            $error['meta'] = $meta;
        }

        return $this->jsonApiResponse(['errors' => [$error]], $status);
    }

    /**
     * @param  list<array<string, mixed>>  $resources
     * @return list<array<string, mixed>>
     */
    private function uniqueResources(array $resources): array
    {
        $unique = [];

        foreach ($resources as $resource) {
            if (! isset($resource['type'], $resource['id'])) {
                continue;
            }

            $unique[$resource['type'].':'.$resource['id']] ??= $resource;
        }

        return array_values($unique);
    }

    /**
     * @return array<string, string>
     */
    private function paginationLinks(Request $request, LengthAwarePaginator $paginator): array
    {
        $query = $request->query();
        unset($query['page']);

        $page = $paginator->currentPage();
        $size = $paginator->perPage();

        $url = function (int $number) use ($request, $query, $size): string {
            $params = $query;
            $params['page'] = ['number' => $number, 'size' => $size];

            return $request->url().'?'.http_build_query($params);
        };

        $links = [
            'self' => $url($page),
        ];

        $links['first'] = $url(1);
        $links['last'] = $url($paginator->lastPage());

        if ($page > 1) {
            $links['prev'] = $url($page - 1);
        }

        if ($page < $paginator->lastPage()) {
            $links['next'] = $url($page + 1);
        }

        return $links;
    }
}
