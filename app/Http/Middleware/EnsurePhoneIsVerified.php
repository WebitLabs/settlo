<?php

namespace App\Http\Middleware;

use App\Filament\Personal\Pages\VerifyPhone;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When SMS phone verification is enabled, an owner whose mobile number is not
 * confirmed yet is sent to the "Verify your phone" page. The profile (to fix
 * the number), logout and email verification stay reachable. The email is
 * verified first, so this only applies once the email is confirmed.
 */
class EnsurePhoneIsVerified
{
    /**
     * Route names that never redirect.
     *
     * @var list<string>
     */
    private const array EXEMPT_ROUTES = [
        'filament.app.pages.verify-phone',
        'filament.app.pages.profile',
        'filament.app.auth.logout',
        'filament.app.auth.email-verification.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (self::mustVerify($user) && ! $request->routeIs(...self::EXEMPT_ROUTES)) {
            return redirect()->to(VerifyPhone::getUrl(panel: 'app'));
        }

        return $next($request);
    }

    /**
     * Whether the feature is on and this owner still has to confirm their phone.
     */
    public static function mustVerify(mixed $user): bool
    {
        return (bool) config('settlo.phone_verification.enabled')
            && $user instanceof User
            && $user->isOwner()
            && $user->hasVerifiedEmail()
            && $user->phone_verified_at === null;
    }
}
