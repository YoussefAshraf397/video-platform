<?php

namespace App\Modules\Users\Models;

use App\Modules\Users\Database\Factories\UserFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Internal to the Users module. Other modules use Contracts\UserDirectory.
 *
 * @property string $id
 * @property string $email
 * @property string $display_name
 * @property string $status
 * @property CarbonImmutable|null $email_verified_at
 */
#[UseFactory(UserFactory::class)]
final class User extends Model
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;   // UUIDv7 primary keys (ADR-013)

    protected $guarded = [];

    protected function casts(): array
    {
        return ['email_verified_at' => 'immutable_datetime'];
    }
}
