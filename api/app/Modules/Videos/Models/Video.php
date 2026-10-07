<?php

namespace App\Modules\Videos\Models;

use App\Modules\Videos\Contracts\VideoStatus;
use App\Modules\Videos\Database\Factories\VideoFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Internal to the Videos module.
 *
 * @property string $id
 * @property string $public_id
 * @property string $uploader_user_id
 * @property string $title
 * @property string|null $description
 * @property string|null $language
 * @property int|null $category_id
 * @property string $visibility
 * @property string $status
 * @property string $moderation_status
 * @property bool $age_restricted
 * @property bool $made_for_kids
 * @property bool $comments_enabled
 * @property Carbon|null $published_at
 * @property int $state_version
 * @property int|null $duration_ms
 * @property int|null $source_width
 * @property int|null $source_height
 * @property int|null $processing_version
 * @property string|null $pre_block_status
 * @property string|null $blocked_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Category|null $category
 */
#[UseFactory(VideoFactory::class)]
final class Video extends Model
{
    /** @use HasFactory<VideoFactory> */
    use HasFactory;

    use HasUuids;   // UUIDv7 primary keys (ADR-013)

    public const VISIBILITIES = ['public', 'unlisted', 'private'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'age_restricted' => 'boolean',
            'made_for_kids' => 'boolean',
            'comments_enabled' => 'boolean',
            'published_at' => 'datetime',
            'state_version' => 'integer',
            'duration_ms' => 'integer',
            'source_width' => 'integer',
            'source_height' => 'integer',
            'processing_version' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Who may see a video that isn't theirs (design doc §13.2). Unlisted counts: knowing the
     * unguessable public_id is the permission.
     */
    public function isVisibleToPublic(): bool
    {
        return $this->deleted_at === null
            && $this->status === VideoStatus::Published->value
            && in_array($this->visibility, ['public', 'unlisted'], true)
            && $this->moderation_status !== 'blocked';
    }
}
