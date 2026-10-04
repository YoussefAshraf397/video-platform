<?php

namespace App\Modules\Users\Http\Controllers;

use App\Modules\Users\Models\User;
use App\Modules\Users\Rules\UniqueHandle;
use App\Modules\Users\Services\Accounts;
use App\Platform\Api\Errors\ApiProblem;
use App\Platform\Api\Http\CallerId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in user's own profile. */
final class MeController
{
    private const EDITABLE = ['display_name', 'handle', 'bio'];

    public function show(Request $request): JsonResponse
    {
        return new JsonResponse(self::present(User::query()->findOrFail(CallerId::from($request))));
    }

    public function update(Request $request, Accounts $accounts): JsonResponse
    {
        $userId = CallerId::from($request);

        // Unknown fields (e.g. "email", which needs re-verification) are an error, not ignored.
        $unknown = array_diff(array_keys($request->all()), self::EDITABLE);
        if ($unknown !== []) {
            throw new ApiProblem(422, 'VALIDATION_FAILED', 'The request is invalid', errors: array_values(array_map(
                fn (string $field) => ['field' => $field, 'code' => 'UNKNOWN_FIELD', 'message' => "The {$field} field can't be changed here."],
                $unknown,
            )));
        }

        $changes = $request->validate([
            // bail: report one problem per field, and don't query for handles that are malformed.
            'display_name' => ['bail', 'sometimes', 'required', 'string', 'max:100'],
            'handle' => ['bail', 'sometimes', 'required', 'string', 'regex:'.Accounts::HANDLE_PATTERN, new UniqueHandle($userId)],
            'bio' => ['bail', 'sometimes', 'nullable', 'string', 'max:500'],
        ]);

        return new JsonResponse(self::present($accounts->updateProfile($userId, $changes)));
    }

    /** @return array<string, mixed> */
    private static function present(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'handle' => $user->handle,
            'display_name' => $user->display_name,
            'bio' => $user->bio,
            'status' => $user->status,
            'created_at' => $user->created_at?->toIso8601ZuluString(),
        ];
    }
}
