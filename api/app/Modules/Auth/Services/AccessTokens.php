<?php

namespace App\Modules\Auth\Services;

use DateInterval;
use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\JWT\Validation\Validator;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Throwable;

/**
 * Issues and verifies access tokens: Ed25519-signed JWTs (alg EdDSA) with claims
 * iss, aud, sub (user id), sid (session id), jti, iat, nbf, exp and email_verified.
 */
final class AccessTokens
{
    private const LEEWAY_SECONDS = 30;

    private readonly Eddsa $signer;

    /** @var non-empty-string */
    private readonly string $issuer;

    /** @var non-empty-string */
    private readonly string $audience;

    /** @var array{id: non-empty-string, key: Key}|null */
    private readonly ?array $signingKey;

    /** @var array<string, Key> public keys by key id */
    private readonly array $publicKeys;

    /**
     * @param  array{id: non-empty-string, private: non-empty-string, public: non-empty-string}|null  $signingKey
     * @param  array<string, non-empty-string>  $publicKeys  base64 public keys by key id (current and previous)
     */
    public function __construct(
        string $issuer,
        string $audience,
        private readonly int $ttlSeconds,
        ?array $signingKey,
        array $publicKeys,
        private readonly ClockInterface $clock,
    ) {
        if ($issuer === '' || $audience === '' || $ttlSeconds <= 0) {
            throw new InvalidArgumentException('Access tokens need an issuer, an audience and a positive TTL.');
        }
        $this->issuer = $issuer;
        $this->audience = $audience;
        $this->signer = new Eddsa;
        $this->signingKey = $signingKey === null ? null
            : ['id' => $signingKey['id'], 'key' => InMemory::base64Encoded($signingKey['private'])];
        $this->publicKeys = array_map(fn (string $key) => InMemory::base64Encoded($key), $publicKeys);
    }

    /**
     * Builds the service from config('auth.tokens'). Blank key settings mean "not configured";
     * issuing then fails loudly, while verification still works with whatever keys exist.
     *
     * @param  array<mixed>  $config
     */
    public static function fromConfig(array $config, ClockInterface $clock): self
    {
        $string = fn (mixed $value): string => is_string($value) ? trim($value) : '';
        $signing = is_array($config['signing_key'] ?? null) ? $config['signing_key'] : [];
        $previous = is_array($config['previous_key'] ?? null) ? $config['previous_key'] : [];

        $id = $string($signing['id'] ?? null);
        $private = $string($signing['private'] ?? null);
        $public = $string($signing['public'] ?? null);

        $publicKeys = [];
        if ($id !== '' && $public !== '') {
            $publicKeys[$id] = $public;
        }
        $previousId = $string($previous['id'] ?? null);
        $previousPublic = $string($previous['public'] ?? null);
        if ($previousId !== '' && $previousPublic !== '') {
            $publicKeys[$previousId] = $previousPublic;
        }

        return new self(
            $string($config['issuer'] ?? null),
            $string($config['audience'] ?? null),
            is_numeric($config['access_ttl_seconds'] ?? null) ? (int) $config['access_ttl_seconds'] : 0,
            $id !== '' && $private !== '' && $public !== '' ? ['id' => $id, 'private' => $private, 'public' => $public] : null,
            $publicKeys,
            $clock,
        );
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    public function issue(string $userId, string $sessionId, bool $emailVerified): string
    {
        if ($this->signingKey === null) {
            throw new RuntimeException('JWT signing key is not configured; run php artisan auth:jwt-keys.');
        }
        if ($userId === '' || $sessionId === '') {
            throw new InvalidArgumentException('Access tokens need a user id and a session id.');
        }
        $now = $this->clock->now();

        return (new Builder(new JoseEncoder, ChainedFormatter::withUnixTimestampDates()))
            ->withHeader('kid', $this->signingKey['id'])
            ->issuedBy($this->issuer)
            ->permittedFor($this->audience)
            ->relatedTo($userId)
            ->identifiedBy((string) Str::uuid7())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify("+{$this->ttlSeconds} seconds"))
            ->withClaim('sid', $sessionId)
            ->withClaim('email_verified', $emailVerified)
            ->getToken($this->signer, $this->signingKey['key'])
            ->toString();
    }

    /** Returns the verified claims, or null for any invalid, expired or foreign token. */
    public function verify(string $jwt): ?AccessTokenClaims
    {
        if ($jwt === '') {
            return null;
        }
        try {
            $token = (new Parser(new JoseEncoder))->parse($jwt);
        } catch (Throwable) {
            return null;
        }
        if (! $token instanceof UnencryptedToken) {
            return null;
        }

        $kid = $token->headers()->get('kid');
        $key = is_string($kid) ? ($this->publicKeys[$kid] ?? null) : null;
        if ($key === null) {
            return null;
        }

        $valid = (new Validator)->validate(
            $token,
            new SignedWith($this->signer, $key),   // also rejects alg "none" and other algorithms
            new IssuedBy($this->issuer),
            new PermittedFor($this->audience),
            new StrictValidAt($this->clock, new DateInterval('PT'.self::LEEWAY_SECONDS.'S')),
        );
        if (! $valid) {
            return null;
        }

        $claims = $token->claims();
        $sub = $claims->get('sub');
        $sid = $claims->get('sid');
        $iat = $claims->get('iat');
        if (! is_string($sub) || ! is_string($sid) || ! $iat instanceof DateTimeImmutable) {
            return null;
        }

        return new AccessTokenClaims($sub, $sid, (bool) $claims->get('email_verified'), $iat);
    }
}
