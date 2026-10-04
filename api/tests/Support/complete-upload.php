<?php

/*
 * Runs one :complete in its own process, for ConcurrentCompletionTest. Waits until a shared start
 * time so several copies hit the database at the same moment. Prints "ok:<status>" or "problem:<code>".
 *
 * Usage: php complete-upload.php <caller-id> <session-id> <parts-json> <start-at-unix-float>
 */

use App\Modules\Uploads\Services\UploadCompletion;
use App\Platform\Api\Errors\ApiProblem;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $callerId, $sessionId, $partsJson, $startAt] = $argv;
time_sleep_until((float) $startAt);

try {
    $session = $app->make(UploadCompletion::class)->complete($callerId, $sessionId, json_decode($partsJson, true));
    echo "ok:{$session->status}";
} catch (ApiProblem $p) {
    echo "problem:{$p->errorCode}";
}
