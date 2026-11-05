<?php

namespace App\Modules\Playback\Services;

use App\Modules\Playback\Contracts\MediaUrls;
use App\Platform\Api\Errors\ApiProblem;
use Aws\CloudFront\UrlSigner;

/**
 * CloudFront signed URLs (canned policy) for single media objects such as thumbnails. The expiry is
 * the next full hour plus one hour, so a URL is stable within an hour (cacheable by browsers) and
 * always valid for at least 60 minutes.
 */
final class SignedUrls implements MediaUrls
{
    public function signedUrl(string $key): string
    {
        $keyPairId = (string) config('playback.key_pair_id');
        $privateKey = base64_decode((string) config('playback.private_key'), true);
        if ($keyPairId === '' || $privateKey === false || $privateKey === '') {
            throw new ApiProblem(503, 'PLAYBACK_UNAVAILABLE', 'Playback is not configured');
        }

        $url = rtrim((string) config('playback.media_base_url'), '/').'/'.ltrim($key, '/');
        $expires = now()->startOfHour()->addHours(2)->getTimestamp();

        return (new UrlSigner($keyPairId, $privateKey))->getSignedUrl($url, $expires);
    }
}
