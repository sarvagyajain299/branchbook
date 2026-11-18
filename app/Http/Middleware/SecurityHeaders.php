<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Adds browser security headers to every web response. */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        // Never let another site put this one in a frame (clickjacking).
        $headers->set('X-Frame-Options', 'DENY');
        // Don't guess content types from file contents.
        $headers->set('X-Content-Type-Options', 'nosniff');
        // Only set a default: the manage-booking pages already ask for the stricter "no-referrer".
        if (! $headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        // HSTS ("always use HTTPS") is only meaningful over HTTPS, and only switched on when the
        // deployment is ready for it, because browsers remember it for a long time.
        if ($request->isSecure() && config('branchbook.hsts')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($request->attributes->get('csp_profile') === 'backpack') {
            // The admin panel (Backpack) is built on inline scripts and styles, so it gets a looser
            // policy than the public pages. It is still limited to our own origin for everything
            // else, cannot be framed, and is reachable only by logged-in HQ and manager accounts.
            $headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
                "style-src 'self' 'unsafe-inline'",
                "font-src 'self' data:",
                "img-src 'self' data:",
                "connect-src 'self'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
            ]));
        } elseif (! file_exists(public_path('hot'))) {
            // Not while the Vite dev server runs (public/hot): it needs inline code and websockets.
            $headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                // 'unsafe-eval' is required by the standard Alpine.js build used by the staff area
                // (Breeze menus). Moving to Alpine's CSP build would remove it.
                "script-src 'self' 'unsafe-eval'",
                "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
                "font-src 'self' https://fonts.bunny.net",
                "img-src 'self' data:",
                "connect-src 'self'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
            ]));
        }

        return $response;
    }
}
