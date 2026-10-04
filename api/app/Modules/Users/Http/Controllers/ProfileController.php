<?php

namespace App\Modules\Users\Http\Controllers;

use App\Modules\Users\Models\User;
use App\Platform\Api\Errors\ApiProblem;
use Illuminate\Http\JsonResponse;

/** Public profiles, by handle. No email or account details. */
final class ProfileController
{
    public function show(string $handle): JsonResponse
    {
        $user = User::query()
            ->whereRaw('lower(handle) = ?', [strtolower($handle)])
            ->where('status', 'active')   // suspended and deleted accounts look like they don't exist
            ->first();

        if ($user === null) {
            throw new ApiProblem(404, 'USER_NOT_FOUND', 'User not found');
        }

        return new JsonResponse([
            'handle' => $user->handle,
            'display_name' => $user->display_name,
            'bio' => $user->bio,
            'created_at' => $user->created_at?->toIso8601ZuluString(),
        ]);
    }
}
