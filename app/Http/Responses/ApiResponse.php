<?php

namespace App\Http\Responses;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * One JSON envelope for API success and error responses.
 *
 * Laravel rewrites ModelNotFoundException to NotFoundHttpException and
 * AuthorizationException to AccessDeniedHttpException before render callbacks,
 * so those prepared HTTP exceptions are what a missing model and a denied
 * action look like here. The body never includes a trace, file, or exception class.
 */
final class ApiResponse
{
    /**
     * Fixed 422 summary. Per-field text stays in `errors`.
     *
     * ValidationException::getMessage() is the first field error (and, with
     * more than one error, a count of the rest). Neither that text nor APP_DEBUG
     * may change the envelope message.
     */
    private const VALIDATION_MESSAGE = 'Validation failed.';

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public static function success(mixed $data, string $message, int $status = 200, ?array $meta = null): JsonResponse
    {
        $payload = [
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param  array<string, list<string>>|null  $errors
     * @param  array<string, mixed>  $headers
     */
    public static function error(string $message, ?array $errors = null, int $status = 422, array $headers = []): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => $errors,
        ], $status, $headers);
    }

    /**
     * Render an API exception. Null lets Laravel use an exception that already carries its own response.
     *
     * Unexpected throwables stay on Laravel's reporter; this method only chooses the JSON body.
     */
    public static function fromException(Throwable $e): ?JsonResponse
    {
        if ($e instanceof HttpResponseException) {
            return null;
        }

        if ($e instanceof ValidationException) {
            return self::error(self::VALIDATION_MESSAGE, $e->errors(), $e->status);
        }

        if ($e instanceof AuthenticationException) {
            return self::error(
                $e->getMessage() !== '' ? $e->getMessage() : 'Unauthenticated.',
                status: Response::HTTP_UNAUTHORIZED,
            );
        }

        if ($e instanceof AuthorizationException) {
            return self::error(self::forbiddenMessage($e), status: Response::HTTP_FORBIDDEN);
        }

        if ($e instanceof ModelNotFoundException) {
            return self::error('Resource not found.', status: Response::HTTP_NOT_FOUND);
        }

        if ($e instanceof HttpExceptionInterface) {
            return self::fromHttpException($e);
        }

        return self::error('Something went wrong.', status: Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    private static function fromHttpException(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();

        $message = match (true) {
            $status === Response::HTTP_UNAUTHORIZED => 'Unauthenticated.',
            $status === Response::HTTP_FORBIDDEN => self::forbiddenMessage($e),
            $status === Response::HTTP_NOT_FOUND => 'Resource not found.',
            $status === Response::HTTP_TOO_MANY_REQUESTS => 'Too Many Attempts.',
            $status >= Response::HTTP_INTERNAL_SERVER_ERROR => 'Something went wrong.',
            default => Response::$statusTexts[$status] ?? 'Request failed.',
        };

        return self::error($message, status: $status, headers: $e->getHeaders());
    }

    private static function forbiddenMessage(Throwable $e): string
    {
        $message = $e->getMessage();

        return $message !== '' ? $message : 'This action is unauthorized.';
    }
}
