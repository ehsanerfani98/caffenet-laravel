<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Goftino live-chat widget was removed at the owner's request: the
 * inline snippet is gone from app.blade.php and every goftino origin was
 * dropped from the SecurityHeaders CSP. These tests pin that removal from
 * both sides — a restored snippet would run against a CSP that blocks it
 * (the widget breaks silently in the console), and stale goftino origins
 * in the policy would keep the app wider than it needs to be. Either
 * regression must fail loudly here.
 */
class GoftinoRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_shell_no_longer_loads_the_goftino_widget(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // No snippet, no loader URL, no localStorage key, no comment.
        $this->assertStringNotContainsString('goftino', $html);
    }

    public function test_csp_no_longer_whitelists_goftino_origins(): void
    {
        $resp = $this->get('/');

        $resp->assertOk();
        $csp = (string) $resp->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('goftino', $csp);

        // The rest of the policy must stay intact after the narrowing.
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' https://www.gstatic.com", $csp);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString('https://fcm.googleapis.com', $csp);
        $this->assertStringContainsString("frame-src 'self'", $csp);
    }
}
