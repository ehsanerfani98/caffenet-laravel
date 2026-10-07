<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Support\Formatter;
use App\Models\SmsLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SMS audit log — smsRouter. Reading needs sms.view; deleting a row or
 * clearing the whole archive needs sms.delete (or the sms.manage umbrella).
 */
class SmsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = SmsLog::orderByDesc('timestamp')->limit(200)->get();

        return response()->json($logs->map([Formatter::class, 'smsLog'])->values()->all());
    }

    /** Delete a single SMS log row. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        SmsLog::where('id', $id)->delete();

        return response()->json(['message' => 'لاگ پیامک حذف شد.']);
    }

    /** Delete the entire SMS log archive. */
    public function clear(Request $request): JsonResponse
    {
        $deleted = SmsLog::query()->delete();

        return response()->json([
            'message' => 'تمام لاگ‌های پیامک حذف شدند.',
            'deleted' => $deleted,
        ]);
    }
}
