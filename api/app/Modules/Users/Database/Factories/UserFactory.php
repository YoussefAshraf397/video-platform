<?php

namespace App\Modules\Users\Database\Factories;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
final class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'display_name' => fake()->name(),
            'status' => 'active',
            'email_verified_at' => now(),
        ];
    }

    public function unverified(): self
    {
        return $this->state(['email_verified_at' => null]);
    }
}
