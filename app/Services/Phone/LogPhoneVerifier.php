<?php

namespace App\Services\Phone;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Development phone verifier: no SMS is sent. The 6-digit code is kept hashed
 * in the cache and written to the log in the local environment only.
 */
class LogPhoneVerifier implements PhoneVerifier
{
    public function sendCode(User $user): void
    {
        $code = $this->generateCode();

        $this->store($user, $code);

        if (app()->environment('local')) {
            Log::info('Phone OTP', ['user' => $user->getKey(), 'code' => $code]);
        }
    }

    public function verify(User $user, string $code): bool
    {
        $key = self::cacheKey($user);

        /** @var array{hash: string, attempts: int, expires_at: int}|null $pending */
        $pending = Cache::get($key);

        if ($pending === null || $pending['attempts'] >= $this->maxAttempts() || $pending['expires_at'] <= now()->getTimestamp()) {
            return false;
        }

        if (Hash::check(trim($code), $pending['hash'])) {
            Cache::forget($key);

            return true;
        }

        $pending['attempts']++;
        Cache::put($key, $pending, now()->setTimestamp($pending['expires_at']));

        return false;
    }

    public function hasPendingCode(User $user): bool
    {
        return Cache::has(self::cacheKey($user));
    }

    public function forget(User $user): void
    {
        Cache::forget(self::cacheKey($user));
    }

    public static function cacheKey(User $user): string
    {
        return 'phone-otp:'.$user->getKey();
    }

    protected function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    protected function store(User $user, string $code): void
    {
        $expiresAt = now()->addMinutes($this->ttlMinutes());

        Cache::put(
            self::cacheKey($user),
            ['hash' => Hash::make($code), 'attempts' => 0, 'expires_at' => $expiresAt->getTimestamp()],
            $expiresAt,
        );
    }

    private function ttlMinutes(): int
    {
        return (int) config('settlo.phone_verification.code_ttl_minutes', 10);
    }

    private function maxAttempts(): int
    {
        return (int) config('settlo.phone_verification.max_attempts', 5);
    }
}
