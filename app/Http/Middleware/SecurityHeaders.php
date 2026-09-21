<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the baseline browser security headers to every response (panels,
 * Inertia pages, downloads and the cron/webhook endpoints alike), so the
 * protection does not depend on a panel remembering to opt in.
 *
 * HSTS is only emitted over HTTPS — sending it over plain HTTP would pin a
 * local development host to a scheme it cannot serve. The Content-Security
 * Policy is deliberately conservative: Filament, Livewire and Alpine need
 * inline scripts/styles and `eval`, so the value that is actually worth
 * having here is the framing, base-uri, form-action and object-src lockdown.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;

        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=(), payment=(), interest-cohort=()');

        $maxAge = (int) config('settlo.security_headers.hsts_max_age');

        if ($request->isSecure() && $maxAge > 0) {
            $headers->set('Strict-Transport-Security', "max-age={$maxAge}; includeSubDomains");
        }

        if (config('settlo.security_headers.csp_enabled')) {
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        }

        return $response;
    }

    /**
     * The policy allows what the Filament/Livewire/Alpine/Vite stack needs and
     * nothing else. In local development the Vite dev server is served from its
     * own origin, so `public/hot` is honoured for script/style/connect sources.
     */
    private function contentSecurityPolicy(): string
    {
        $extra = $this->viteDevServerOrigin();

        $directives = [
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
            'frame-ancestors' => ["'self'"],
            'form-action' => ["'self'"],
            'img-src' => ["'self'", 'data:', 'blob:', 'https:'],
            'font-src' => ["'self'", 'data:', 'https://fonts.bunny.net'],
            'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.bunny.net', ...$extra],
            'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'", 'blob:', ...$extra],
            'connect-src' => ["'self'", 'blob:', 'data:', 'ws:', 'wss:', ...$extra],
            'media-src' => ["'self'", 'blob:', 'data:'],
            'worker-src' => ["'self'", 'blob:'],
            'frame-src' => ["'self'", 'blob:', 'data:'],
        ];

        return collect($directives)
            ->map(fn (array $sources, string $directive): string => $directive.' '.implode(' ', array_unique($sources)))
            ->implode('; ');
    }

    /**
     * @return list<string>
     */
    private function viteDevServerOrigin(): array
    {
        $hotFile = public_path('hot');

        if (! app()->environment('local') || ! is_file($hotFile)) {
            return [];
        }

        $url = trim((string) file_get_contents($hotFile));
        $origin = parse_url($url, PHP_URL_SCHEME) && parse_url($url, PHP_URL_HOST)
            ? parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST).':'.(parse_url($url, PHP_URL_PORT) ?: '5173')
            : null;

        return $origin === null ? [] : [$origin, str_replace(['http://', 'https://'], ['ws://', 'wss://'], $origin)];
    }
}
