<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Helmet-equivalent security headers with a strict CSP matching the original
 * app (self-only scripts/styles, data/blob images, self-hosted fonts).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $csp = implode('; ', [
            "default-src 'self'",
            // Firebase SDK is loaded dynamically from gstatic.com only when
            // FCM is enabled. The 'unsafe-inline' is required for inline
            // styles used throughout the SPA (sheets, swatches, positioning).
            "script-src 'self' 'unsafe-inline' https://www.gstatic.com",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            // FCM needs connect-src to: firebaseinstallations.googleapis.com
            // (token registration) + fcm.googleapis.com (message delivery).
            "connect-src 'self' https://fcm.googleapis.com https://firebaseinstallations.googleapis.com https://www.gstatic.com",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);

        // The SPA shell is a Blade page whose <script> URLs are versioned by
        // filemtime. If the HTML itself were cached, a browser could keep loading
        // an old shell that points at old (polling) scripts — so never cache it.
        // Static assets under /css/ /js/ /fonts/ /uploads/ /media/ ARE cached
        // (their URLs include ?v=<filemtime> so cache busting works); this is
        // important for woff2 fonts which are 80KB+ and would otherwise be
        // re-downloaded on every page load.
        $isStaticAsset = $request->isMethod('GET') && (
            $request->is('css/*') ||
            $request->is('js/*') ||
            $request->is('js/screens/*') ||
            $request->is('fonts/*') ||
            $request->is('uploads/*') ||
            $request->is('media/*') ||
            $request->is('JalaliDatePicker-main/*') ||
            $request->is('*.woff2') ||
            $request->is('*.woff') ||
            $request->is('*.ttf') ||
            $request->is('*.otf') ||
            $request->is('*.css') ||
            $request->is('*.js') ||
            $request->is('*.png') ||
            $request->is('*.jpg') ||
            $request->is('*.jpeg') ||
            $request->is('*.gif') ||
            $request->is('*.svg') ||
            $request->is('*.ico') ||
            $request->is('manifest.webmanifest') ||
            $request->is('sw.js')
        );

        if ($request->isMethod('GET') && ! $request->is('api/*') && ! $isStaticAsset) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('X-PHP-Version');

        return $response;
    }
}
