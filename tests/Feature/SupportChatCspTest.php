<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The floating «اگر سوالی دارید کلیک کنید» support button embeds the
 * Goftino live-chat page (https://www.goftino.com/c/…) in an iframe.
 * The app's CSP is otherwise self-only (default-src 'self') — without an
 * explicit frame-src directive the frame would fall back to 'self' and be
 * SILENTLY blocked. This pins the whitelist so a future CSP tightening
 * cannot break the support chat unnoticed.
 */
class SupportChatCspTest extends TestCase
{
    use RefreshDatabase;

    public function test_csp_allows_the_goftino_chat_frame(): void
    {
        $resp = $this->get('/');

        $resp->assertOk();
        $csp = (string) $resp->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('frame-src', $csp);
        $this->assertStringContainsString('https://www.goftino.com', $csp);
        $this->assertStringContainsString('https://*.goftino.com', $csp);
    }

    public function test_shell_contains_the_support_chat_mount_and_script(): void
    {
        $resp = $this->get('/');

        $resp->assertOk();
        $html = $resp->getContent();
        $this->assertStringContainsString('id="support-chat"', $html);
        $this->assertStringContainsString('support-chat.js', $html);
    }
}
