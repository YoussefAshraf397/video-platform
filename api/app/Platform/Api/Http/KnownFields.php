<?php

namespace App\Platform\Api\Http;

use App\Platform\Api\Errors\ApiProblem;
use Illuminate\Http\Request;

/**
 * Rejects request body fields an endpoint doesn't accept, instead of silently ignoring them.
 * A client sending `email` or `status` to an endpoint that can't change it gets told so.
 */
final class KnownFields
{
    /** @param  list<string>  $allowed */
    public static function assert(Request $request, array $allowed): void
    {
        $unknown = array_diff(array_map('strval', array_keys($request->all())), $allowed);
        if ($unknown === []) {
            return;
        }

        throw new ApiProblem(422, 'VALIDATION_FAILED', 'The request is invalid', errors: array_values(array_map(
            fn (string $field) => ['field' => $field, 'code' => 'UNKNOWN_FIELD', 'message' => "The {$field} field can't be set here."],
            $unknown,
        )));
    }
}
