<?php

namespace Tests\Feature;

use App\Services\FirebasePushService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression guard: FCM topic subscribe (Instance ID API).
 *
 * The old implementation sent the subscribe POST with a hand-set
 * «Content-Length: 0» header via ->post($url). Laravel turns a bodyless
 * post() into Guzzle's json option with an empty array, which Guzzle
 * json-encodes into a 2-byte body ('[]') — contradicting the manual
 * Content-Length: 0. Guzzle (RequestFraming::analyze, called by
 * CurlFactory before any network I/O) then refuses to dispatch with
 * «Content-Length does not match the request body size», the exception
 * was swallowed by the catch and logged as
 * «FirebasePushService: topic subscribe failed» — so EVERY subscribe
 * attempt failed silently, no device ever joined the "all" topic and
 * topic broadcasts reached nobody.
 *
 * The fix dispatches a genuinely empty body via send('POST', ...) and
 * lets Guzzle derive Content-Length: 0 itself. These tests pin that
 * behaviour: the outgoing subscribe request must NOT hand-set
 * Content-Length and must carry an empty body.
 */
class FcmTopicSubscribeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // FCM fully configured and live (real HTTP endpoints, faked below)
        // so the test drives the actual subscribe path, including the
        // OAuth2 access-token exchange.
        SettingsService::set('fcmEnabled', '1');
        SettingsService::set('fcmTestMode', '0');
        SettingsService::set('fcmProjectId', 'caffenet-test');
        SettingsService::set('fcmServiceAccount', $this->serviceAccountJson());

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
            ], 200),
            'iid.googleapis.com/*' => Http::response([], 200),
        ]);
    }

    /**
     * The subscribe request must reach the wire WITHOUT a hand-set
     * Content-Length header and with a genuinely empty body.
     */
    public function test_topic_subscribe_dispatches_empty_body_without_manual_content_length(): void
    {
        $ok = FirebasePushService::subscribeToTopic('test-fcm-token', 'all');

        $this->assertTrue($ok, 'subscribeToTopic must succeed (200 from the faked IID endpoint)');

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://iid.googleapis.com/iid/v1/test-fcm-token/rel/topics/all') {
                return false;
            }

            $this->assertSame('POST', $request->method());
            $this->assertFalse(
                $request->hasHeader('Content-Length'),
                'Content-Length must be derived by Guzzle, never hand-set — a manual '
                ."header contradicts the json-encoded body and Guzzle refuses to dispatch"
            );
            $this->assertSame('', $request->body(), 'The IID subscribe request must have an empty body');

            return true;
        });
    }

    /**
     * The OAuth2 token exchange that precedes the subscribe call stays a
     * normal form POST carrying the jwt-bearer grant — pinned so a future
     * refactor of getAccessToken does not break the subscribe flow that
     * depends on it. (Unlike the IID request, a form POST legitimately
     * carries a derived Content-Length header for its urlencoded body.)
     */
    public function test_access_token_request_is_consistent_form_post(): void
    {
        $ok = FirebasePushService::subscribeToTopic('test-fcm-token', 'all');

        $this->assertTrue($ok);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://oauth2.googleapis.com/token') {
                return false;
            }

            $this->assertSame('POST', $request->method());
            $this->assertStringContainsString(
                'grant_type='.urlencode('urn:ietf:params:oauth:grant-type:jwt-bearer'),
                $request->body()
            );
            $this->assertStringContainsString('assertion=', $request->body());

            return true;
        });
    }

    /**
     * Throwaway RSA key generated ONLY for this test (openssl_sign needs a
     * real key to produce the JWT); it guards no secret.
     */
    private function serviceAccountJson(): string
    {
        $pem = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQDTkl/xldn5fGTT
lzOOcWdn5JCUPB3h0sX2KFOp0SaLVX6owzn9z4PaqoOJtJGWXgAfZ4dcAdiZ4QLH
BO/8vUG5WFYQZGbQH3W+geJCQ7pFiYfUeTFgoE21KEb51d7F/BnsNFkZ0n0p/qaL
v78x2EMeuhi8eSh9j5xE1H9/C0rYBywQKvmcgzIJ5cfvYnWKZ+bZ5ujHwbH34J4p
+8P39C3VM+jb/XOebs8xZpwpLbcBIXaaVzmhtlZSKOMWzGqA/eC91LsxnyYvaMj1
RD2zV4eW4AOAHjmFs8RWvLBHgiPMO/YE91P782stPFZtuH8XMdKVW5h4Uw4Ymk1f
7ubKXCvBAgMBAAECggEAR9yL4g63/tmVlZjh1hwGt28W/IJ+09k3jCieYiu8H89m
OgQ1ilQVwQvxqbA/UweSmxgbDdFCtOGMg/TVXs8Zp86tTNwNX9uPrqmHJGLEviG8
lRqk/uD0A/guQ2W1SSIb2/Lb4L1x7fWktAro8hE2mxUymbG9YwiIV4il1Y0MWttW
LAtnIeww4rpL5chbx2ABhRo7dtHcyKSjjo1e+QQYNbu2yz5rUDlDH6Et66+sEnZK
Eg6nZ5JHh9X0TgAfRBGtuLfbRgYSJ3r8di8WGFv61zLkvANN1MiBlsy7zFFUpD46
q4T+P/WuQ+PwXKHA0Z9buYyajfBVQn6nHQQhZ+4x0QKBgQD8PB8M4mEJRlfFCsfz
G5IDAb8m5D7QdWCQ3EWoOD0bnu2XACqdE7ZAVPwCujtAg6GWCduSJWPSijjCGgU+
yhojQI0+DlsOzqCUKomDYOG8/xajMaFtKEwubKfr9wFFfTtZH9PFUV4gH7A0aIE4
MSDWO6fc118xLMH+QH62cTNG3wKBgQDWut2Qem3A/wthyzUmS17fgeef1dVd3qvj
rmV+5rxBV8POfVJq6czObNvKvMOLNmZLSNmStpZU7UY+G2kJWU+jyvVIoMoXrqZl
Q6xfPrOUu/hYRNOg5GSdPJTl4jle3ZMv5Y6JgNB4zn/kvUpxaVp7KrsAARApzcLD
AVDVcrIBXwKBgA9bQbCxH6NG6xUOAPpmFU+ljZO6lbBvVP+8yWjic6X42RI2amHB
z3i/M9CesfjNisrtsraFHDy3OZlnqCYYe9rllTPP/+842UHsDQ84mMW+6gvpe6B/
sZOp/dc55JPsee6FJ4l92LMv+rRWIHfhVdR1MVUar6r4xJ7MImrNHYgVAoGAOH1W
doDOyNwPyX1lERr47YHGRpVBmX1P6QjjKJxY4DPPjk4H1ZqGR7P5qn84TjJUfO/A
VUl1iQgW15Xsbwow2l01ZBLwt4Q5wCAqg7ACHFnK/qW0qvoKwhbC8SsvfpZ59zrD
4caikXV5zdpCcE2QASD/qwOclfLMjvKUqmKPE68CgYAk8YZRCWEukxk4oquT+y8Q
2oYwXQSW+0htGqzdFUP3oQn1P1egCYovNkSaKj803Aqm9pz/Fl9tPhjzYXO3FXXG
GMMUP2IEijNxpwnhTOv6NO/WdQKhZjHXwEEZZQMZy9wf7IL1VMSLxGfO4FBHJH/7
nGBUDZegabll9ZhAaIezcg==
-----END PRIVATE KEY-----
PEM;

        return json_encode([
            'client_email' => 'push@caffenet-test.iam.gserviceaccount.com',
            'private_key' => $pem,
        ]);
    }
}
