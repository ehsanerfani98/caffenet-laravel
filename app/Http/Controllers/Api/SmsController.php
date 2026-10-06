<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Support\Formatter;
use App\Models\SmsLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SMS audit log — smsRouter (sms.view permission).
 */
class SmsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = SmsLog::orderByDesc('timestamp')->limit(200)->get();

        return response()->json($logs->map([Formatter::class, 'smsLog'])->values()->all());
    }
}
