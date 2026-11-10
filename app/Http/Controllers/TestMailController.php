<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Temporary endpoint that sends one test email, to check the mail provider
 * on a deployed environment. Guarded by a hardcoded token for now; remove it
 * once delivery is confirmed.
 */
class TestMailController
{
    public const TOKEN = 'a5eac6b2787f68b059bf8868361b48ef8fd22bffb8f29af0';

    public const RECIPIENT = 'marius@webitfactory.io';

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(hash_equals(self::TOKEN, (string) $request->query('token', '')), 403);

        $details = [
            'mailer' => config('mail.default'),
            'host' => config('mail.mailers.smtp.host'),
            'from' => config('mail.from.address'),
            'to' => self::RECIPIENT,
        ];

        try {
            Mail::raw('Test email sent from '.config('app.url').' at '.now()->toDateTimeString().'.', function ($message): void {
                $message->to(self::RECIPIENT)->subject('Settlo test email');
            });
        } catch (Throwable $exception) {
            return response()->json([...$details, 'sent' => false, 'error' => $exception::class.': '.$exception->getMessage()], 500);
        }

        return response()->json([...$details, 'sent' => true]);
    }
}
