<?php

namespace App\Platform\Api\Errors;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every API error as RFC 9457 problem details (application/problem+json).
 * Framework exception messages are never exposed: they can contain class names, SQL, or
 * other internals. Only ApiProblem carries a client-facing `detail`.
 */
final class ProblemRenderer
{
    public static function shouldRender(Request $request): bool
    {
        return $request->is('v1', 'v1/*') || $request->expectsJson();
    }

    public static function render(Throwable $e): Response
    {
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        $problem = self::toProblem($e);

        $body = array_filter([
            'type' => $problem->type(),
            'title' => $problem->title,
            'status' => $problem->status,
            'detail' => $problem->detail,
            'code' => $problem->errorCode,
            'request_id' => Context::get('request_id'),
            'errors' => $problem->errors,
        ], fn ($value) => $value !== null && $value !== []);

        if ($problem->status >= 500 && config('app.debug')) {
            $body['debug'] = ['exception' => $e::class, 'message' => $e->getMessage()];
        }

        return new JsonResponse($body, $problem->status, [
            ...$problem->headers,
            'Content-Type' => 'application/problem+json',
        ]);
    }

    private static function toProblem(Throwable $e): ApiProblem
    {
        return match (true) {
            $e instanceof ApiProblem => $e,
            $e instanceof ValidationException => ApiProblem::fromValidator($e->validator),
            $e instanceof AuthenticationException => new ApiProblem(401, 'UNAUTHENTICATED', 'Authentication required'),
            $e instanceof HttpExceptionInterface => self::fromHttpException($e),
            default => new ApiProblem(500, 'INTERNAL_ERROR', 'Internal server error'),
        };
    }

    private static function fromHttpException(HttpExceptionInterface $e): ApiProblem
    {
        $status = $e->getStatusCode();
        $title = Response::$statusTexts[$status] ?? 'Error';

        return new ApiProblem(
            $status,
            Str::upper(Str::slug($title, '_')),
            $title,
            headers: $e->getHeaders(),
        );
    }
}
