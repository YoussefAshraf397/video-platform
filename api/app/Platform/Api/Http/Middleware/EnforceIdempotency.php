<?php

namespace App\Platform\Api\Http\Middleware;

use App\Platform\Api\Errors\ApiProblem;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Makes a side-effecting endpoint safe to retry (route middleware alias: `idempotent`).
 *
 * The client sends an Idempotency-Key header. The first successful (2xx) response is stored
 * for 24 h, and retries with the same key and the same request get that response back
 * (`Idempotent-Replayed: true`) without running the handler again. Failed attempts are not
 * stored, so the client can retry them. Status codes follow the IETF Idempotency-Key draft:
 * 400 missing/invalid key, 409 same key still in flight, 422 same key with a different request.
 */
final class EnforceIdempotency
{
    public const HEADER = 'Idempotency-Key';

    private const RESULT_TTL_SECONDS = 86_400;

    /** Must exceed the longest time a request can run, or a slow request could run twice. */
    private const IN_FLIGHT_TTL_SECONDS = 60;

    private const REPLAYED_HEADERS = ['Content-Type', 'Location', 'ETag'];

    public function __construct(private readonly Cache $cache) {}

    public static function cacheKey(string $scope, string $key): string
    {
        return 'idempotency:'.hash('sha256', $scope."\n".$key);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->headers->get(self::HEADER, '');
        if ($key === '') {
            throw new ApiProblem(400, 'IDEMPOTENCY_KEY_REQUIRED', 'Idempotency-Key header is required');
        }
        if (! preg_match('/^[\x21-\x7E]{1,255}$/', $key)) {
            throw new ApiProblem(400, 'IDEMPOTENCY_KEY_INVALID', 'Idempotency-Key must be 1-255 printable ASCII characters');
        }

        // Whatever the guard returns; this middleware doesn't depend on a user class.
        $user = ($request->getUserResolver())();
        $scope = $user instanceof Authenticatable ? $user->getAuthIdentifier() : 'ip:'.$request->ip();
        $cacheKey = self::cacheKey((string) $scope, $key);
        $fingerprint = hash('sha256', $request->method().' '.$request->path()."\n".$request->getContent());

        $inFlight = ['state' => 'in_flight', 'fingerprint' => $fingerprint];
        if (! $this->cache->add($cacheKey, $inFlight, self::IN_FLIGHT_TTL_SECONDS)) {
            return $this->replay($this->cache->get($cacheKey) ?? $inFlight, $fingerprint);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->cache->forget($cacheKey);
            throw $e;
        }

        if ($response->isSuccessful()) {
            $this->cache->put($cacheKey, [
                'state' => 'completed',
                'fingerprint' => $fingerprint,
                'status' => $response->getStatusCode(),
                'headers' => array_filter(array_combine(
                    self::REPLAYED_HEADERS,
                    array_map(fn ($name) => $response->headers->get($name), self::REPLAYED_HEADERS),
                )),
                'body' => $response->getContent(),
            ], self::RESULT_TTL_SECONDS);
        } else {
            $this->cache->forget($cacheKey);
        }

        return $response;
    }

    /**
     * @param  array{state: 'in_flight', fingerprint: string}|array{state: 'completed', fingerprint: string, status: int, headers: array<string, string>, body: string}  $stored
     */
    private function replay(array $stored, string $fingerprint): Response
    {
        if (! hash_equals($stored['fingerprint'], $fingerprint)) {
            throw new ApiProblem(422, 'IDEMPOTENCY_KEY_REUSED', 'Idempotency-Key was already used for a different request');
        }

        if ($stored['state'] !== 'completed') {
            throw new ApiProblem(
                409,
                'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                'A request with this Idempotency-Key is still being processed',
                headers: ['Retry-After' => '1'],
            );
        }

        return new Response($stored['body'], $stored['status'], [...$stored['headers'], 'Idempotent-Replayed' => 'true']);
    }
}
