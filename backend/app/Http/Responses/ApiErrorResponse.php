<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ApiErrorResponse
{
    public static function render(Response $response, Throwable $exception, Request $request): Response
    {
        $status = $response->getStatusCode();

        if ($status < 400 || ! $request->is('api', 'api/*')) {
            return $response;
        }

        $body = [
            'error' => $status === 419 ? 'Session expired' : (Response::$statusTexts[$status] ?? 'Request failed'),
            'code' => $status,
        ];

        if ($status === 422 && $exception instanceof ValidationException) {
            $body['error'] = 'Validation failed';
            $body['errors'] = (object) $exception->errors();
        }

        // Keep protocol headers such as Allow, but discard metadata for the replaced body.
        $headers = Arr::except($response->headers->all(), [
            'content-type', 'content-length', 'content-encoding', 'content-range',
            'transfer-encoding', 'etag', 'last-modified', 'content-digest', 'repr-digest',
        ]);

        return (new JsonResponse($body, $status, $headers))->prepare($request);
    }
}
