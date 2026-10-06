<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Support\Formatter;
use App\Models\SliderImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Home-page slider management — sliderRouter in routes.ts.
 * Images live in the public media disk (5MB, image/* whitelist) and are
 * served publicly from /media/.
 */
class SliderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser'); // optional auth
        $isAdmin = $user && ($user->role === 'admin');

        $query = SliderImage::query();
        if (! $isAdmin) {
            $query->where('is_active', true);
        }

        return response()->json(
            $query->orderBy('sort_order')->get()->map([Formatter::class, 'slider'])->values()->all()
        );
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate(['image' => ['required', 'image', 'max:5120']]);

        $file = $request->file('image');
        if (! $file->isValid()) {
            return response()->json(['error' => 'تصویر معتبر نیست.'], 400);
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            return response()->json(['error' => 'پسوند تصویر مجاز نیست.'], 400);
        }

        $stored = $file->storeAs('', 'sld-'.\Illuminate\Support\Str::uuid().'.'.$ext, 'media');

        return response()->json(['imageUrl' => '/media/'.basename($stored)]);
    }

    public function store(Request $request): JsonResponse
    {
        $body = (array) $request->json()->all();
        $imageUrl = (string) ($body['imageUrl'] ?? '');

        if ($imageUrl === '') {
            return response()->json(['error' => 'تصویر اسلاید الزامی است.'], 400);
        }
        if (! str_starts_with($imageUrl, '/media/')) {
            return response()->json(['error' => 'تصویر اسلاید باید از مسیر رسانه عمومی باشد.'], 400);
        }

        $slide = ! empty($body['id']) ? SliderImage::find($body['id']) : null;

        $payload = [
            'image_url' => $imageUrl,
            'title' => (string) ($body['title'] ?? ''),
            'subtitle' => (string) ($body['subtitle'] ?? ''),
            'cta_label' => (string) ($body['ctaLabel'] ?? ''),
            'cta_target' => (string) ($body['ctaTarget'] ?? ''),
            'is_active' => (bool) ($body['isActive'] ?? true),
        ];

        if ($slide) {
            // Free the replaced image when it changed.
            if ($slide->image_url !== $imageUrl) {
                Storage::disk('media')->delete(basename($slide->image_url));
            }
            $slide->update($payload);
        } else {
            $payload['sort_order'] = (int) SliderImage::max('sort_order') + 1;
            $payload['created_at'] = now()->toIso8601String();
            $slide = SliderImage::create($payload);
        }

        return response()->json(Formatter::slider($slide));
    }

    public function reorder(Request $request): JsonResponse
    {
        $ids = $request->json('ids');
        if (! is_array($ids)) {
            return response()->json(['error' => 'ترتیب اسلایدها نامعتبر است.'], 400);
        }
        foreach (array_values($ids) as $index => $id) {
            SliderImage::where('id', $id)->update(['sort_order' => $index]);
        }

        return response()->json(['message' => 'ترتیب اسلایدها ذخیره شد.']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $slide = SliderImage::find($id);
        if ($slide) {
            Storage::disk('media')->delete(basename($slide->image_url));
            $slide->delete();
        }

        return response()->json(['message' => 'اسلاید حذف شد.']);
    }
}
