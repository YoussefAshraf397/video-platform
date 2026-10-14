<?php

namespace App\Modules\Playback\Http\Controllers;

use App\Modules\Playback\Services\AccessRules;
use App\Modules\Playback\Services\SignedCookies;
use App\Modules\Processing\Contracts\PlayableMedia;
use App\Modules\Videos\Contracts\VideoAccess;
use App\Platform\Api\Errors\ApiProblem;
use App\Platform\Api\Http\CallerId;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * GET /v1/videos/{video}/playback (design doc §13): decides whether the caller may watch, then
 * returns the stream URL and signed cookies for it. Signed in or not. Called again by the player
 * to refresh, on a 403 from the CDN or shortly before `auth.expires_at`.
 */
final class PlaybackController
{
    /** Cookie lifetime: the video's length plus 30 minutes, kept between 1 and 6 hours (§13.4). */
    private const MIN_TTL = 3600;

    private const MAX_TTL = 6 * 3600;

    private const SLACK = 1800;

    public function __construct(
        private readonly VideoAccess $videos,
        private readonly PlayableMedia $media,
        private readonly AccessRules $rules,
        private readonly SignedCookies $signer,
    ) {}

    public function show(Request $request, string $video): JsonResponse
    {
        $policy = $this->rules->authorize($this->videos->policy($video), CallerId::tryFrom($request));
        $output = $this->media->current($policy->id)
            ?? throw new ApiProblem(409, 'VIDEO_NOT_PLAYABLE', 'This video cannot be played yet', 'No rendition is ready.');

        $ttl = $policy->durationMs === null
            ? self::MIN_TTL   // duration arrives with completion; the player refreshes on expiry
            : max(self::MIN_TTL, min(self::MAX_TTL, intdiv($policy->durationMs, 1000) + self::SLACK));
        $expiresAt = CarbonImmutable::now()->addSeconds($ttl);
        $base = rtrim((string) config('playback.media_base_url'), '/');
        $values = $this->signer->sign("{$base}/{$output->prefix}*", $expiresAt);

        $response = new JsonResponse([
            'video' => [
                'id' => $policy->publicId,
                'title' => $policy->title,
                'duration_ms' => $policy->durationMs,
                'width' => $policy->width,
                'height' => $policy->height,
            ],
            'streams' => ['hls' => "{$base}/{$output->masterKey}"],
            'auth' => [
                'type' => 'cookie',
                'expires_at' => $expiresAt->toIso8601ZuluString(),
                // Also in the body, for native players that set them as request headers themselves.
                'cookies' => $values,
            ],
            'thumbnails' => [
                'primary' => array_map(fn (array $f) => ['url' => "{$base}/{$f['key']}", 'width' => $f['width'], 'height' => $f['height'], 'format' => $f['format']],
                    $policy->primaryThumbnail),
            ],
            'captions' => [],
            'resume_position_ms' => null,
            'playback_session_id' => (string) Str::uuid7(),
        ]);
        foreach ($this->signer->cookies($values, '/'.$output->prefix, $expiresAt) as $cookie) {
            $response->headers->setCookie($cookie);
        }
        // Credentials: never cached by browsers or proxies.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
