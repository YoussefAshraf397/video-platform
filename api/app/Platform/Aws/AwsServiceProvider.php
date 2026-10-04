<?php

namespace App\Platform\Aws;

use Aws\S3\S3Client;
use Aws\Sns\SnsClient;
use Aws\Sqs\SqsClient;
use Illuminate\Support\ServiceProvider;

/** One configured AWS SDK client per service, for any module (config/aws.php). */
final class AwsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SnsClient::class, fn () => new SnsClient($this->config()));
        $this->app->singleton(SqsClient::class, fn () => new SqsClient($this->config()));
        $this->app->singleton(S3Client::class, fn () => new S3Client([
            ...$this->config(),
            'use_path_style_endpoint' => (bool) config('aws.use_path_style_endpoint'),
        ]));
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $aws = config('aws');

        return array_filter([
            'region' => $aws['region'],
            'version' => 'latest',
            'endpoint' => $aws['endpoint'],
            // Explicit keys locally; in AWS the SDK uses the ECS task role.
            'credentials' => $aws['key'] && $aws['secret']
                ? ['key' => $aws['key'], 'secret' => $aws['secret']]
                : null,
        ]);
    }
}
