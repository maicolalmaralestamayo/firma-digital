<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Turns framework and domain exceptions into JSON:API error documents
 * (https://jsonapi.org/format/#errors).
 */
final class JsonApiExceptionHandler
{
    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        return match (true) {
            $exception instanceof ValidationException => self::validation($exception),
            $exception instanceof AuthenticationException => self::error(
                401,
                'unauthenticated',
                'No autenticado',
                'Adjunta un token Bearer válido para acceder a este recurso.',
                headers: ['WWW-Authenticate' => 'Bearer'],
            ),
            $exception instanceof AuthorizationException => self::error(
                403,
                'forbidden',
                'Acción no permitida',
                $exception->getMessage() !== '' ? $exception->getMessage() : 'No tienes permiso para realizar esta acción.',
            ),
            $exception instanceof ModelNotFoundException,
            $exception instanceof NotFoundHttpException => self::error(
                404,
                'not_found',
                'Recurso no encontrado',
                'El recurso solicitado no existe o no está disponible.',
            ),
            $exception instanceof DomainException => self::error(
                $exception->status,
                $exception->errorCode,
                $exception->title,
                $exception->getMessage(),
                $exception->meta,
                $exception->headers,
            ),
            $exception instanceof \Illuminate\Http\Exceptions\ThrottleRequestsException => self::error(
                429,
                'too_many_requests',
                'Demasiadas solicitudes',
                'Espera un momento antes de volver a intentarlo.',
                headers: $exception->getHeaders(),
            ),
            $exception instanceof HttpExceptionInterface => self::error(
                $exception->getStatusCode(),
                self::codeForStatus($exception->getStatusCode()),
                Response::$statusTexts[$exception->getStatusCode()] ?? 'Error',
                $exception->getMessage() !== '' ? $exception->getMessage() : 'La solicitud no pudo completarse.',
                headers: $exception->getHeaders(),
            ),
            default => self::unexpected($exception),
        };
    }

    private static function validation(ValidationException $exception): JsonResponse
    {
        $errors = [];

        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $errors[] = [
                    'status' => '422',
                    'code' => 'invalid_attribute',
                    'title' => 'Datos no válidos',
                    'detail' => $message,
                    'source' => ['pointer' => '/data/attributes/'.str_replace('.', '/', (string) $field)],
                ];
            }
        }

        return self::payload($errors, 422);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    private static function error(
        int $status,
        string $code,
        string $title,
        string $detail,
        array $meta = [],
        array $headers = [],
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

        return self::payload([$error], $status, $headers);
    }

    /**
     * @param  list<array<string, mixed>>  $errors
     * @param  array<string, string>  $headers
     */
    private static function payload(array $errors, int $status, array $headers = []): JsonResponse
    {
        return new JsonResponse(
            ['errors' => $errors],
            $status,
            ['Content-Type' => 'application/vnd.api+json'] + $headers,
        );
    }

    private static function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            413 => 'payload_too_large',
            415 => 'unsupported_media_type',
            422 => 'unprocessable_entity',
            429 => 'too_many_requests',
            default => 'http_error',
        };
    }

    private static function unexpected(Throwable $exception): JsonResponse
    {
        report($exception);

        return self::error(
            500,
            'server_error',
            'Error interno',
            config('app.debug')
                ? $exception->getMessage()
                : 'Ocurrió un error inesperado. Intenta nuevamente o contacta al administrador.',
        );
    }
}
