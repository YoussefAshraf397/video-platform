<?php

namespace App\Platform\Api\Http;

use App\Platform\Api\Errors\ApiProblem;
use Illuminate\Http\Request;

/**
 * Optimistic concurrency with ETag / If-Match (design doc §22). The ETag is a resource's version
 * number; the client sends it back in If-Match and the write is a conditional update on that
 * version, so a change made since the client read the resource is never silently overwritten.
 */
final class Preconditions
{
    public static function etag(int $version): string
    {
        return '"'.$version.'"';
    }

    /**
     * The version the client last saw, from `If-Match`. Missing → 428 (the client must say what it
     * is changing). Present but not one of our ETags (`*`, weak or foreign tags) → 412, since it can
     * never match a version.
     */
    public static function expectedVersion(Request $request): int
    {
        $header = $request->headers->get('If-Match');
        if ($header === null || trim($header) === '') {
            throw new ApiProblem(428, 'PRECONDITION_REQUIRED', 'If-Match header required',
                'Send the ETag from your last read in If-Match.');
        }

        if (preg_match('/^\s*"([1-9][0-9]{0,9})"\s*$/', $header, $m) !== 1) {
            throw self::failed();
        }

        return (int) $m[1];
    }

    public static function failed(): ApiProblem
    {
        return new ApiProblem(412, 'PRECONDITION_FAILED', 'The resource has changed',
            'Fetch it again, reapply your change and retry with the new ETag.');
    }
}
