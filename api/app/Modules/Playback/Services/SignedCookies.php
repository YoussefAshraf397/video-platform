<?php

namespace App\Modules\Playback\Services;

use App\Platform\Api\Errors\ApiProblem;
use Aws\CloudFront\CookieSigner;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * CloudFront signed cookies (design doc §13.3): one custom policy covers a whole processing
 * version, `/media/{video_id}/v{n}/*` (master, rendition playlists, segments, thumbnails), so
 * manifests stay static and cacheable and nothing is rewritten per viewer.
 */
final class SignedCookies
{
    /**
     * @return array<string, string> CloudFront-Policy, CloudFront-Signature, CloudFront-Key-Pair-Id, CloudFront-Hash-Algorithm
     */
    public function sign(string $resource, CarbonImmutable $expiresAt): array
    {
        $keyPairId = (string) config('playback.key_pair_id');
        $privateKey = base64_decode((string) config('playback.private_key'), true);
        if ($keyPairId === '' || $privateKey === false || $privateKey === '') {
            throw new ApiProblem(503, 'PLAYBACK_UNAVAILABLE', 'Playback is not configured');
        }

        $policy = json_encode(['Statement' => [[
            'Resource' => $resource,
            'Condition' => ['DateLessThan' => ['AWS:EpochTime' => $expiresAt->getTimestamp()]],
        ]]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        /** @var array<string, string> */
        return (new CookieSigner($keyPairId, $privateKey, 'SHA256'))->getSignedCookie(policy: $policy);
    }

    /**
     * The Set-Cookie headers: scoped to the version's path on the media domain, HttpOnly, and
     * Secure when media is served over HTTPS.
     *
     * @param  array<string, string>  $values
     * @return list<Cookie>
     */
    public function cookies(array $values, string $path, CarbonImmutable $expiresAt): array
    {
        $secure = str_starts_with((string) config('playback.media_base_url'), 'https://');
        $domain = config('playback.cookie_domain');

        return array_map(
            fn (string $name, string $value) => Cookie::create($name, $value, $expiresAt, $path, is_string($domain) ? $domain : null, $secure, true, true, Cookie::SAMESITE_LAX),
            array_keys($values),
            $values,
        );
    }
}
