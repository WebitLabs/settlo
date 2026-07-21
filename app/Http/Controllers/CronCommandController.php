<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs a whitelisted scheduled command over HTTP for serverless deploys where
 * no schedule:run daemon exists and an external cron pinger triggers the
 * schedule instead. Guarded by the CRON_SECRET shared secret, accepted as a
 * bearer token or a ?token= query fallback for pingers without header support.
 */
class CronCommandController
{
    /** @var array<string, string> */
    public const COMMANDS = [
        'expire-trials' => 'settlo:expire-trials',
        'reset-quotas' => 'settlo:reset-quotas',
        'renew-subscriptions' => 'settlo:renew-subscriptions',
        'mark-overdue-invoices' => 'settlo:mark-overdue-invoices',
    ];

    public function __invoke(Request $request, string $command): JsonResponse
    {
        $secret = (string) config('cron.secret');

        abort_if($secret === '', 503, 'Cron secret is not configured.');

        $provided = (string) ($request->bearerToken() ?? $request->query('token', ''));

        abort_unless($provided !== '' && hash_equals($secret, $provided), 403);

        $artisanCommand = self::COMMANDS[$command] ?? abort(404);

        $startedAt = microtime(true);
        $exitCode = Artisan::call($artisanCommand);

        return response()->json([
            'command' => $artisanCommand,
            'exit_code' => $exitCode,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
