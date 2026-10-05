<?php

use App\Platform\Messaging\OutboxMessage;
use App\Platform\Messaging\ProcessedMessage;
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune', ['--model' => [OutboxMessage::class, ProcessedMessage::class]])
    ->daily()
    ->onOneServer();

// Modules schedule their own jobs in their service providers (e.g. uploads:sweep in Uploads).
