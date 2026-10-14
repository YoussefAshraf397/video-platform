<?php

namespace App\Modules\Playback\Console;

use Illuminate\Console\Command;
use RuntimeException;

/**
 * A key pair for CloudFront signed cookies. The public key goes into the distribution's trusted
 * key group (S4-03); the private key into Secrets Manager (or .env locally). Rotate by adding a
 * second key to the group, switching CLOUDFRONT_KEY_PAIR_ID, then removing the old one (§13.4).
 */
final class GenerateCdnKeysCommand extends Command
{
    protected $signature = 'playback:cdn-keys';

    protected $description = 'Generate an RSA key pair for signing CloudFront cookies';

    public function handle(): int
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false || ! openssl_pkey_export($key, $private)) {
            throw new RuntimeException('Could not generate a key pair.');
        }
        $public = openssl_pkey_get_details($key)['key'] ?? throw new RuntimeException('Could not read the public key.');

        $this->line('Public key (CloudFront → Public keys, then add it to the key group):');
        $this->line($public);
        $this->line('Private key, for the API (Secrets Manager in AWS):');
        $this->line('CLOUDFRONT_PRIVATE_KEY='.base64_encode($private));
        $this->line('CLOUDFRONT_KEY_PAIR_ID=<the public key id CloudFront shows, e.g. K2JCJMDEHXQW5F>');

        return self::SUCCESS;
    }
}
