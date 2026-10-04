<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** @var array{id: string, private: string, public: string}|null */
    private static ?array $jwtKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Tests sign access tokens with an ephemeral key pair, never a developer's .env key.
        if (self::$jwtKey === null) {
            $pair = sodium_crypto_sign_keypair();
            self::$jwtKey = [
                'id' => 'test-key',
                'private' => base64_encode(sodium_crypto_sign_secretkey($pair)),
                'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
            ];
        }
        config(['auth.tokens.signing_key' => self::$jwtKey]);
    }

    /**
     * Guards cache the resolved user. Production resolves it fresh on every request (new FPM
     * process, or Octane flushing auth state), so tests must too, or a later request would
     * silently act as an earlier caller.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
