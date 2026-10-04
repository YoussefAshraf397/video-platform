<?php

namespace App\Platform\Api\Errors;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * An error to return to the client as RFC 9457 problem details. Throw it from anywhere in a
 * request; ProblemRenderer turns it into the response.
 *
 * `errorCode` is the stable, machine-readable identifier clients branch on (e.g. VIDEO_NOT_FOUND).
 */
final class ApiProblem extends RuntimeException
{
    /**
     * @param  list<array{field: string, code: string, message: string}>  $errors
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly string $title,
        public readonly ?string $detail = null,
        public readonly array $errors = [],
        public readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($detail ?? $title, 0, $previous);
    }

    public static function fromValidator(Validator $validator): self
    {
        $errors = [];
        $messages = $validator->errors();

        foreach ($validator->failed() as $field => $failedRules) {
            $field = (string) $field;   // numeric field names come back as int array keys
            $fieldMessages = $messages->get($field);
            foreach (array_keys($failedRules) as $i => $rule) {
                $message = $fieldMessages[$i] ?? $fieldMessages[0] ?? null;
                $errors[] = [
                    'field' => $field,
                    'code' => Str::upper(Str::snake(class_basename((string) $rule))),
                    'message' => is_string($message) ? $message : 'Invalid value.',
                ];
            }
        }

        return new self(422, 'VALIDATION_FAILED', 'The request is invalid', errors: $errors);
    }

    public function type(): string
    {
        return '/errors/'.Str::lower(str_replace('_', '-', $this->errorCode));
    }
}
