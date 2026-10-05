<?php

namespace App\Modules\Uploads\Consumers;

use App\Modules\Uploads\Models\UploadSession;
use App\Modules\Uploads\Services\UploadCompletion;
use App\Platform\Api\Errors\ApiProblem;
use App\Platform\Messaging\MessageConsumer;
use Illuminate\Support\Facades\Log;

/**
 * Reconciliation (ADR-003, S3-05): S3 `ObjectCreated` notifications for the uploads bucket, via the
 * `s3-upload-events` queue. Only our own `:complete` can create a session's object (clients can
 * only PUT parts), so the object existing while its session isn't `completed` means that call
 * crashed after S3 assembled the file. This finishes it.
 *
 * Usually the session is already completed and the message is a no-op. The queue delays delivery
 * (90 s) so the complete call that created the object has normally committed by then; if a live
 * call still holds the claim, the message is retried and taken over once the claim goes stale.
 * Completion is idempotent, so S3's duplicate notifications are harmless.
 */
final class UploadObjectCreatedConsumer implements MessageConsumer
{
    private const KEY = '#^uploads/([0-9a-f-]{36})/([0-9a-f-]{36})/source$#';

    public function __construct(private readonly UploadCompletion $completion) {}

    public function name(): string
    {
        return 's3-upload-events';
    }

    public function handle(array $message): bool
    {
        $acted = false;
        foreach ($message['Records'] ?? [] as $record) {   // s3:TestEvent messages have no Records
            if (! str_starts_with((string) ($record['eventName'] ?? ''), 'ObjectCreated:')) {
                continue;
            }
            $key = urldecode((string) ($record['s3']['object']['key'] ?? ''));
            if (preg_match(self::KEY, $key, $m) !== 1) {
                continue;
            }

            $session = UploadSession::query()->whereKey($m[2])->where('video_id', $m[1])->first();
            if ($session === null) {
                Log::warning('upload.object_without_session', ['key' => $key]);

                continue;
            }
            if ($session->status === 'completed') {
                continue;
            }

            try {
                $this->completion->reconcile($session);
                Log::info('upload.reconciled', ['session_id' => $session->id]);
                $acted = true;
            } catch (ApiProblem $p) {
                if ($p->errorCode === 'UPLOAD_COMPLETING') {
                    throw $p;   // a live call holds it: let SQS redeliver, and take over once it's stale
                }
                Log::warning('upload.reconcile_skipped', ['session_id' => $session->id, 'outcome' => $p->errorCode]);
            }
        }

        return $acted;
    }
}
