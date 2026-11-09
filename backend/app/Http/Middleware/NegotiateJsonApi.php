<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON:API media type negotiation (https://jsonapi.org/format/#content-negotiation).
 *
 * Clients may send `Accept: application/vnd.api+json`, `application/json` or
 * `*\/*`; anything else is answered with 406. JSON payloads produced by the API
 * always carry the JSON:API media type.
 */
class NegotiateJsonApi
{
    public const MEDIA_TYPE = 'application/vnd.api+json';

    public function handle(Request $request, Closure $next): Response
    {
        $accept = $request->headers->get('Accept');

        if ($accept !== null && ! $this->acceptsJsonApi($accept)) {
            return new JsonResponse([
                'errors' => [[
                    'status' => '406',
                    'code' => 'not_acceptable',
                    'title' => 'Not Acceptable',
                    'detail' => 'This API only produces application/vnd.api+json. Send an Accept header that allows it.',
                ]],
            ], 406, ['Content-Type' => self::MEDIA_TYPE]);
        }

        $response = $next($request);

        if ($response instanceof JsonResponse
            && ! $response->headers->has('Content-Type')) {
            $response->headers->set('Content-Type', self::MEDIA_TYPE);
        }

        return $response;
    }

    private function acceptsJsonApi(string $accept): bool
    {
        foreach (explode(',', $accept) as $mediaRange) {
            $type = strtolower(trim(explode(';', $mediaRange)[0]));

            if (in_array($type, [self::MEDIA_TYPE, 'application/json', '*/*', 'application/*'], true)) {
                return true;
            }
        }

        return false;
    }
}
