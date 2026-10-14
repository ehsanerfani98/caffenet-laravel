<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Artisan Runner — runs a whitelist of Artisan commands via HTTP.
 *
 * Designed for cPanel shared hosting without SSH access. The user
 * triggers commands by visiting /_artisan/run/{command}?token=...
 * (or POSTing) from their browser, with a token stored in .env.
 *
 * Security:
 *  - Only commands on the WHITELIST can be invoked.
 *  - A static token in .env (ARTISAN_RUNNER_TOKEN) must match.
 *  - Optionally, an IP whitelist via ARTISAN_RUNNER_IPS (comma-separated).
 *  - All invocations are logged to storage/logs/artisan-runner.log.
 *
 * After deployment is complete, the user can disable this entirely by
 * setting ARTISAN_RUNNER_TOKEN=disabled in .env.
 */
class ArtisanRunnerController extends Controller
{
    /**
     * Command alias → [actual Artisan command, allowed options].
     * The user invokes the alias; we expand and run.
     */
    private const WHITELIST = [
        'migrate' => ['command' => 'migrate', 'opts' => ['--force' => true]],
        'migrate:fresh' => ['command' => 'migrate:fresh', 'opts' => ['--force' => true]],
        'migrate:rollback' => ['command' => 'migrate:rollback', 'opts' => ['--force' => true]],
        'db:seed' => ['command' => 'db:seed', 'opts' => ['--force' => true]],
        'cache:clear' => ['command' => 'cache:clear', 'opts' => []],
        'config:cache' => ['command' => 'config:cache', 'opts' => []],
        'config:clear' => ['command' => 'config:clear', 'opts' => []],
        'route:cache' => ['command' => 'route:cache', 'opts' => []],
        'route:clear' => ['command' => 'route:clear', 'opts' => []],
        'view:cache' => ['command' => 'view:cache', 'opts' => []],
        'view:clear' => ['command' => 'view:clear', 'opts' => []],
        'queue:work' => ['command' => 'queue:work', 'opts' => ['--stop-when-empty' => true, '--max-time' => 50, '--tries' => 1]],
        'schedule:run' => ['command' => 'schedule:run', 'opts' => []],
        'caffenet:cleanup' => ['command' => 'caffenet:cleanup', 'opts' => []],
        'queue:slots:generate' => ['command' => 'queue:slots:generate', 'opts' => ['--hours' => 24]],
    ];

    public function run(Request $request, string $command): JsonResponse
    {
        // 1. Token check.
        $expectedToken = (string) env('ARTISAN_RUNNER_TOKEN', '');
        if ($expectedToken === '' || $expectedToken === 'disabled') {
            return response()->json(['error' => 'Artisan Runner غیرفعال است.'], 404);
        }
        $token = (string) $request->query('token', $request->header('X-Artisan-Token', ''));
        if (! hash_equals($expectedToken, $token)) {
            $this->audit($command, 'unauthorized: bad token', $request);
            return response()->json(['error' => 'توکن نامعتبر.'], 403);
        }

        // 2. IP whitelist (optional).
        $allowedIps = array_filter(array_map('trim', explode(',', (string) env('ARTISAN_RUNNER_IPS', ''))));
        if (! empty($allowedIps)) {
            $ip = $request->ip();
            if (! in_array($ip, $allowedIps, true)) {
                $this->audit($command, "unauthorized: bad ip {$ip}", $request);
                return response()->json(['error' => 'آی‌پی غیرمجاز.'], 403);
            }
        }

        // 3. Whitelist check.
        if (! array_key_exists($command, self::WHITELIST)) {
            $this->audit($command, 'unauthorized: not in whitelist', $request);
            return response()->json(['error' => 'دستور مجاز نیست.'], 400);
        }

        // 4. Run.
        $spec = self::WHITELIST[$command];
        $exitCode = Artisan::call($spec['command'], $spec['opts']);
        $output = Artisan::output();

        $this->audit($command, "exit={$exitCode}", $request, $output);

        return response()->json([
            'command' => $command,
            'exit_code' => $exitCode,
            'output' => mb_substr($output, 0, 8000),
        ]);
    }

    private function audit(string $command, string $status, Request $request, string $output = ''): void
    {
        $line = sprintf(
            "[%s] %s from %s — %s%s\n",
            now()->toIso8601String(),
            $command,
            $request->ip(),
            $status,
            $output !== '' ? ' :: '.mb_substr($output, 0, 500) : '',
        );

        try {
            Storage::disk('local')->append('logs/artisan-runner.log', $line);
        } catch (\Throwable $e) {
            Log::info('ArtisanRunner audit: '.$line);
        }
    }
}
