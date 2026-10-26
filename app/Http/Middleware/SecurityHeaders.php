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
            // styles used throughout the SPA (sheets, swatches, positioning)
            // AND for the inline Goftino widget snippet in app.blade.php.
            // The goftino host covers the widget script itself (www) and the
            // client.js it loads from its CDN (cdn.goftino.com).
            "script-src 'self' 'unsafe-inline' https://www.gstatic.com https://*.goftino.com",
            // The Goftino widget renders its whole chat UI inside an iframe
            // whose document the widget itself writes — that frame INHERITS
            // THIS CSP, so every resource the chat loads must be whitelisted
            // here: client.css + its fonts from cdn.goftino.com, inline
            // <style>/style attributes the widget injects, avatars/images
            // from cdn + s4 (upload server).
            "style-src 'self' 'unsafe-inline' https://*.goftino.com",
            "img-src 'self' data: blob: https://*.goftino.com",
            "font-src 'self' data: https://*.goftino.com",
            // FCM needs connect-src to: firebaseinstallations.googleapis.com
            // (Firebase Installation ID) + fcmregistrations.googleapis.com
            // (the actual token subscribe POST — without it getToken throws
            // messaging/token-subscribe-failed) + fcm.googleapis.com (delivery).
            // The goftino hosts cover the widget's XHR API calls (www) and
            // its WebSocket gateway (wss://ws*.goftino.com).
            "connect-src 'self' https://fcm.googleapis.com https://firebaseinstallations.googleapis.com https://fcmregistrations.googleapis.com https://www.gstatic.com https://*.goftino.com wss://*.goftino.com",
            // The Goftino widget creates its chat iframe at runtime
            // (about:srcdoc — allowed by 'self'); the goftino origins cover
            // any frame the widget navigates to its own pages.
            "frame-src 'self' https://*.goftino.com",
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
