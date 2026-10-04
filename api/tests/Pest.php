<?php

use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

/** Adds a fresh Idempotency-Key, which every `idempotent` POST requires. */
function withKey(array $headers): array
{
    return [...$headers, 'Idempotency-Key' => (string) Str::uuid()];
}
