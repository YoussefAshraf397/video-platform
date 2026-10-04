<?php

namespace App\Modules\Videos\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Seeded by the videos migration.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property int $sort_order
 * @property bool $is_active
 */
final class Category extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
