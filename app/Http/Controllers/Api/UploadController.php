<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * File upload endpoints — order files (public/uploads, 30MB) with a
 * MIME/extension whitelist identical to ALLOWED_* in the original routes.ts.
 * Files live under public/ so no storage:link symlink is required on shared
 * hosting. Slider images are handled by SliderController into public/media.
 */
class UploadController extends Controller
{
    private const MAX_ORDER_FILE_BYTES = 30 * 1024 * 1024;

    private const ALLOWED_MIME = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/zip', 'application/x-zip-compressed',
        'application/rar', 'application/x-rar-compressed', 'application/vnd.rar',
        'text/plain', 'text/csv',
    ];

    private const ALLOWED_EXT = [
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx',
        'ppt', 'pptx', 'zip', 'rar', 'txt', 'csv',
    ];

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.(int) (self::MAX_ORDER_FILE_BYTES / 1024)],
        ]);

        $file = $request->file('file');
        if (! $file->isValid()) {
            return response()->json(['error' => 'فایل آپلودی معتبر نیست.'], 400);
        }

        if (! in_array($file->getMimeType(), self::ALLOWED_MIME, true)) {
            return response()->json(['error' => 'نوع فایل مجاز نیست.'], 400);
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, self::ALLOWED_EXT, true)) {
            return response()->json(['error' => 'پسوند فایل مجاز نیست.'], 400);
        }

        // Store with a random name; the file lands in public/uploads and is
        // served from /uploads/<name>.
        $stored = $file->storeAs('', 'up-'.\Illuminate\Support\Str::uuid().'.'.$ext, 'uploads');

        $meta = [
            'id' => 'file-'.\Illuminate\Support\Str::uuid(),
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'type' => $file->getMimeType(),
            'url' => '/uploads/'.basename($stored),
            'uploadedAt' => now()->toIso8601String(),
        ];

        return response()->json(['file' => $meta]);
    }

    /** Deletes an uploaded file by its /uploads/... url. */
    public function delete(Request $request): JsonResponse
    {
        $url = (string) ($request->json('url') ?? '');
        $name = basename(parse_url($url, PHP_URL_PATH) ?: '');

        if ($name === '' || ! str_starts_with($url, '/uploads/')) {
            return response()->json(['error' => 'آدرس فایل نامعتبر است.'], 400);
        }

        Storage::disk('uploads')->delete($name);

        return response()->json(['message' => 'فایل حذف شد.']);
    }
}
