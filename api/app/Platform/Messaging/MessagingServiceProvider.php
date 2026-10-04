<?php

namespace App\Platform\Messaging;

use App\Platform\Messaging\Console\ConsumeMessagesCommand;
use App\Platform\Messaging\Console\RelayOutboxCommand;
use Aws\Sns\SnsClient;
use Aws\Sqs\SqsClient;
use Illuminate\Support\ServiceProvider;

final class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SnsClient::class, fn () => new SnsClient($this->awsConfig()));
        $this->app->singleton(SqsClient::class, fn () => new SqsClient($this->awsConfig()));
        $this->app->singleton(OutboxRelay::class, fn ($app) => new OutboxRelay(
            $app->make(SnsClient::class),
            (string) config('messaging.sns_topic_arn_prefix'),
        ));
    }

    public function boot(): void
    {
        $this->commands([RelayOutboxCommand::class, ConsumeMessagesCommand::class]);
    }

    /** @return array<string, mixed> */
    private function awsConfig(): array
    {
        $aws = config('messaging.aws');

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
