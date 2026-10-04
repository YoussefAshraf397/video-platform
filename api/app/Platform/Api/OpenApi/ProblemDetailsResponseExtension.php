<?php

namespace App\Platform\Api\OpenApi;

use Dedoc\Scramble\Support\ExceptionToResponseExtensions\HttpExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApi;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Documents every error response as RFC 9457 problem details, matching what ProblemRenderer
 * returns at runtime. Registered after Scramble's built-in extensions, so it takes priority.
 */
final class ProblemDetailsResponseExtension extends HttpExceptionToResponseExtension
{
    public function shouldHandle(Type $type)
    {
        return $type instanceof ObjectType && $this->status($type) !== null;
    }

    /** @param ObjectType $type */
    public function toResponse(Type $type)
    {
        $status = $this->status($type);

        $error = (new OpenApi\ObjectType)
            ->addProperty('field', new OpenApi\StringType)
            ->addProperty('code', new OpenApi\StringType)
            ->addProperty('message', new OpenApi\StringType)
            ->setRequired(['field', 'code', 'message']);

        $problem = (new OpenApi\ObjectType)
            ->addProperty('type', (new OpenApi\StringType)->setDescription('URI identifying the problem type.'))
            ->addProperty('title', new OpenApi\StringType)
            ->addProperty('status', (new OpenApi\IntegerType)->example($status))
            ->addProperty('detail', new OpenApi\StringType)
            ->addProperty('code', (new OpenApi\StringType)->setDescription('Stable machine-readable error code.'))
            ->addProperty('request_id', new OpenApi\StringType)
            ->addProperty('errors', (new OpenApi\ArrayType)->setItems($error))
            ->setRequired(['type', 'title', 'status', 'code']);

        return Response::make($status)
            ->setDescription($this->description($type))
            ->setContent('application/problem+json', Schema::fromType($problem));
    }

    private function status(ObjectType $type): ?int
    {
        return match (true) {
            $type->isInstanceOf(ValidationException::class) => 422,
            $type->isInstanceOf(AuthenticationException::class) => 401,
            $type->isInstanceOf(AuthorizationException::class) => 403,
            $type->isInstanceOf(ModelNotFoundException::class),
            $type->isInstanceOf(RecordsNotFoundException::class) => 404,
            $type->isInstanceOf(HttpException::class) => $this->getResponseCode(
                count($type->templateTypes ?? []) > 3 ? ($type->templateTypes[7] ?? null) : ($type->templateTypes[0] ?? null),
                $type,
            ),
            default => null,
        };
    }

    private function description(ObjectType $type): string
    {
        return match (true) {
            $type->isInstanceOf(ValidationException::class) => 'Validation error',
            $type->isInstanceOf(AuthenticationException::class) => 'Unauthenticated',
            $type->isInstanceOf(AuthorizationException::class) => 'Forbidden',
            $type->isInstanceOf(ModelNotFoundException::class),
            $type->isInstanceOf(RecordsNotFoundException::class) => 'Not found',
            default => $this->getDescription($type),
        };
    }
}
