<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Support is provided by the OFFICIAL Goftino live-chat widget: the
 * owner-supplied inline snippet in app.blade.php loads the widget script
 * from https://www.goftino.com on every screen (the SPA has a single shell,
 * so one insertion covers all pages, guests included). The widget then
 * renders its chat UI inside an iframe whose document the widget itself
 * writes — that frame INHERITS our strict CSP, so every host the widget
 * touches (cdn.goftino.com for client.js/css, s4.goftino.com for uploads,
 * ws*.goftino.com for its WebSocket, www.goftino.com for its API) must be
 * whitelisted in SecurityHeaders.php or the chat breaks SILENTLY in the
 * console. These tests pin the whitelist, the snippet's presence and the
 * absence of the removed custom iframe button so neither regression can
 * happen unnoticed.
 */
class GoftinoWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_csp_whitelists_every_goftino_origin_the_widget_needs(): void
    {
        $resp = $this->get('/');

        $resp->assertOk();
        $csp = (string) $resp->headers->get('Content-Security-Policy');

        // widget script (www) + its CDN client.js (cdn) — script-src
        $this->assertStringContainsString(
            "script-src 'self' 'unsafe-inline' https://www.gstatic.com https://*.goftino.com",
            $csp
        );
        // chat iframe inherits this policy: client.css + widget <style> tags
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline' https://*.goftino.com", $csp);
        // avatars, operator photos, uploaded images (cdn + s4)
        $this->assertStringContainsString("img-src 'self' data: blob: https://*.goftino.com", $csp);
        // client.css fonts
        $this->assertStringContainsString("font-src 'self' data: https://*.goftino.com", $csp);
        // XHR API (www) + WebSocket gateway (wss://ws*.goftino.com)
        $this->assertStringContainsString('https://*.goftino.com wss://*.goftino.com', $csp);
        // the widget's own runtime iframe
        $this->assertStringContainsString("frame-src 'self' https://*.goftino.com", $csp);
    }

    public function test_shell_loads_the_official_goftino_widget_snippet(): void
    {
        $resp = $this->get('/');

        $resp->assertOk();
        $html = $resp->getContent();

        // The owner-provided snippet, verbatim: the widget id and the
        // goftino.com/widget/ loader URL (concatenated at runtime, so the
        // pieces are asserted separately).
        $this->assertStringContainsString('!function(){var i="FdMb9y"', $html);
        $this->assertStringContainsString('https://www.goftino.com/widget/"+i', $html);
        $this->assertStringContainsString('localStorage.getItem("goftino_"+i)', $html);
    }

    public function test_the_removed_iframe_button_leaves_no_trace(): void
    {
        $resp = $this->get('/');

        $resp->assertOk();
        $html = $resp->getContent();

        // The custom pill + iframe panel (support-chat.js / #support-chat)
        // was removed — no mount point, no script include, no /c/ frame URL.
        $this->assertStringNotContainsString('support-chat', $html);
        $this->assertStringNotContainsString('goftino.com/c/', $html);
    }
}
