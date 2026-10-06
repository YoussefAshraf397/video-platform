<?php

namespace App\Platform\Messaging;

use App\Platform\Messaging\Console\ConsumeMessagesCommand;
use App\Platform\Messaging\Console\RelayOutboxCommand;
use App\Platform\Observability\Tracing;
use Aws\Sns\SnsClient;
use Illuminate\Support\ServiceProvider;

final class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OutboxRelay::class, fn ($app) => new OutboxRelay(
            $app->make(SnsClient::class),
            (string) config('messaging.sns_topic_arn_prefix'),
            $app->make(Tracing::class),
        ));
    }

    public function boot(): void
    {
        $this->commands([RelayOutboxCommand::class, ConsumeMessagesCommand::class]);
    }
}
