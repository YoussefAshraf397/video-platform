<?php

namespace App\Modules\Playback\Contracts;

use App\Platform\Api\Errors\ApiProblem;

interface MediaUrls
{
    /**
     * A CloudFront signed URL (canned policy) for one media object, `{media_base_url}/{key}?Expires=…`.
     * The expiry is the next full hour plus one hour, so the URL is stable within an hour and valid
     * for at least 60 minutes.
     *
     * @throws ApiProblem 503 PLAYBACK_UNAVAILABLE when signing isn't configured
     */
    public function signedUrl(string $key): string;
}
