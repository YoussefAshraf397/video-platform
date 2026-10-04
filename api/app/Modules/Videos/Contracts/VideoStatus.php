<?php

namespace App\Modules\Videos\Contracts;

/**
 * Video lifecycle states and the legal transitions between them (design doc §12.1–12.3).
 * Visibility and moderation_status are separate attributes, not states.
 */
enum VideoStatus: string
{
    case Draft = 'draft';
    case UploadPending = 'upload_pending';
    case Uploading = 'uploading';
    case Uploaded = 'uploaded';
    case Validating = 'validating';
    case QueuedForProcessing = 'queued_for_processing';
    case Processing = 'processing';
    case Ready = 'ready';
    case Published = 'published';
    case Unpublished = 'unpublished';
    case UploadFailed = 'upload_failed';
    case ProcessingFailed = 'processing_failed';
    case Rejected = 'rejected';
    case Blocked = 'blocked';
    case Deleted = 'deleted';
    case Purged = 'purged';

    /**
     * Where this state can go next. Blocked lists every state a block can be lifted to; the
     * transition service only allows the one the video was in before it was blocked.
     *
     * @return list<self>
     */
    public function next(): array
    {
        $next = match ($this) {
            self::Draft => [self::UploadPending],
            self::UploadPending => [self::Uploading, self::UploadFailed],
            self::Uploading => [self::Uploaded, self::UploadFailed],
            self::UploadFailed => [self::UploadPending],
            self::Uploaded => [self::Validating],
            self::Validating => [self::QueuedForProcessing, self::ProcessingFailed, self::Rejected],
            self::QueuedForProcessing => [self::Processing],
            self::Processing => [self::Ready, self::ProcessingFailed],
            self::ProcessingFailed => [self::QueuedForProcessing],
            self::Ready => [self::Published],
            self::Published => [self::Unpublished],
            self::Unpublished => [self::Published],
            self::Rejected => [],
            self::Blocked => array_values(array_filter(self::cases(), fn (self $s) => $s->isRestorableFromBlock())),
            self::Deleted => [self::Purged],
            self::Purged => [],
        };

        // Moderation can block, and owners or admins can delete, from (almost) anywhere.
        if (! in_array($this, [self::Blocked, self::Deleted, self::Purged], true)) {
            $next[] = self::Blocked;
        }
        if (! in_array($this, [self::Deleted, self::Purged], true)) {
            $next[] = self::Deleted;
        }

        return $next;
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    private function isRestorableFromBlock(): bool
    {
        return ! in_array($this, [self::Blocked, self::Deleted, self::Purged], true);
    }
}
