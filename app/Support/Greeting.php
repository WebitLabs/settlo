<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Time-of-day greeting anchored to Swiss local time, independent of the
 * server timezone.
 */
final class Greeting
{
    public static function now(): string
    {
        $hour = (int) Carbon::now('Europe/Zurich')->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };
    }
}
