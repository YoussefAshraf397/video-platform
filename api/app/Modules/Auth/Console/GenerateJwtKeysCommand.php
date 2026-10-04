<?php

namespace App\Modules\Auth\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Prints a new Ed25519 key pair as environment variables. Put them in .env locally and in
 * Secrets Manager in AWS. To rotate: move the current id/public key to JWT_PREVIOUS_*, deploy
 * the new pair, and remove the previous key after one access-token lifetime (15 minutes).
 */
final class GenerateJwtKeysCommand extends Command
{
    protected $signature = 'auth:jwt-keys';

    protected $description = 'Generate an Ed25519 key pair for signing access tokens';

    public function handle(): int
    {
        $pair = sodium_crypto_sign_keypair();

        $this->line('JWT_KEY_ID='.now()->format('Ymd').'-'.Str::lower(Str::random(6)));
        $this->line('JWT_PRIVATE_KEY='.base64_encode(sodium_crypto_sign_secretkey($pair)));
        $this->line('JWT_PUBLIC_KEY='.base64_encode(sodium_crypto_sign_publickey($pair)));

        return self::SUCCESS;
    }
}
