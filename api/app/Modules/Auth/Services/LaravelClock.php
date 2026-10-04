<?php

namespace App\Modules\Auth\Services;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/** PSR-20 clock backed by now(), so Carbon::setTestNow() controls token time in tests. */
final class LaravelClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return now()->toDateTimeImmutable();
    }
}
