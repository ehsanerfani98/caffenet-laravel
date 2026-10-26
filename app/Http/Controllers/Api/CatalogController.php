<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Support\Formatter;
use App\Models\Category;
use App\Models\Service;
use App\Services\SlugService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Categories + services (the dynamic form-builder catalog).
 * Identical contract to catalogRouter in routes.ts.
 */
class CatalogController extends Controller
{
    public function categories(Request $request): JsonResponse
    {
        // Sort by explicit sort_order first, then by created_at for ties so the
        // home page icon grid shows the admin's intended order.
        return response()->json(
            Category::orderBy('sort_order')->orderBy('created_at')->get()
                ->map([Formatter::class, 'category'])->values()->all()
        );
    }

    public function saveCategory(Request $request): JsonResponse
    {
        $body = (array) $request->json()->all();
        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            return response()->json(['error' => 'عنوان دسته‌بندی الزامی است.'], 400);
        }

        // Sanitize the hex color (e.g. "#a82cc7" or "a82cc7") — only 6-digit
        // hex is accepted. Anything else is stored as empty so the frontend
        // falls back to its default color.
        $iconColor = trim((string) ($body['iconColor'] ?? ''));
        if ($iconColor !== '' && ! preg_match('/^#?[0-9a-fA-F]{6}$/', $iconColor)) {
            return response()->json(['error' => 'رنگ آیکون باید کد هگز ۶ رقمی باشد (مثل #a82cc7).'], 400);
        }
        if ($iconColor !== '' && ! str_starts_with($iconColor, '#')) {
            $iconColor = '#'.$iconColor;
        }

        $sortOrder = (int) ($body['sortOrder'] ?? 0);

        $category = ($body['id'] ?? null) ? Category::find($body['id']) : null;
        if ($category) {
            $category->update([
                'title' => $title,
                'icon_name' => (string) ($body['iconName'] ?? $category->icon_name),
                'icon_color' => $iconColor,
                'sort_order' => $sortOrder,
                'description' => (string) ($body['description'] ?? ''),
            ]);
        } else {
            $category = Category::create([
                'title' => $title,
                'icon_name' => (string) ($body['iconName'] ?? 'Grid3x3'),
                'icon_color' => $iconColor,
                'sort_order' => $sortOrder,
                'description' => (string) ($body['description'] ?? ''),
                'created_at' => now()->toIso8601String(),
            ]);
        }

        return response()->json(Formatter::category($category));
    }

    public function deleteCategory(Request $request, string $id): JsonResponse
    {
        $servicesCount = Service::where('category_id', $id)->count();
        if ($servicesCount > 0) {
            return response()->json(['error' => 'ابتدا خدمات این دسته‌بندی را حذف یا منتقل کنید.'], 400);
        }

        Category::where('id', $id)->delete();

        return response()->json(['message' => 'دسته‌بندی حذف شد.']);
    }

    public function services(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser'); // optional auth
        $isAdmin = $user && $user->role === 'admin';

        $query = Service::query();
        if (! $isAdmin) {
            $query->where('is_active', true);
        }
        // Manual display order first («ترتیب نمایش در دسته» from the service
        // form) — mirrors the categories endpoint. created_at only breaks
        // ties so services with equal sort_order keep a stable order.
        $rows = $query->orderBy('sort_order')->orderBy('created_at')->get();

        return response()->json($rows->map([Formatter::class, 'service'])->values()->all());
    }

    /**
     * Deep-link support for #/service/{slug} — resolves one service by its
     * URL slug (active rows only, unless an admin asks). 404 JSON keeps the
     * client's empty-state path simple.
     */
    public function serviceBySlug(Request $request, string $slug): JsonResponse
    {
        $user = $request->attributes->get('authUser'); // optional auth
        $isAdmin = $user && $user->role === 'admin';

        $query = Service::query()->where('slug', $slug);
        if (! $isAdmin) {
            $query->where('is_active', true);
        }

        $service = $query->first();
        if (! $service) {
            return response()->json(['error' => 'خدمت یافت نشد.'], 404);
        }

        return response()->json(Formatter::service($service));
    }

    public function saveService(Request $request): JsonResponse
    {
        $body = (array) $request->json()->all();

        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            return response()->json(['error' => 'عنوان خدمت الزامی است.'], 400);
        }
        if (! Category::find($body['categoryId'] ?? '')) {
            return response()->json(['error' => 'دسته‌بندی انتخاب‌شده معتبر نیست.'], 400);
        }

        // Sanitize iconColor — same rule as categories: 6-digit hex, leading
        // # added if missing. NULL/empty → frontend falls back to default.
        $iconColor = trim((string) ($body['iconColor'] ?? ''));
        if ($iconColor !== '' && ! preg_match('/^#?[0-9a-fA-F]{6}$/', $iconColor)) {
            return response()->json(['error' => 'رنگ آیکون باید کد هگز ۶ رقمی باشد (مثل #a82cc7).'], 400);
        }
        if ($iconColor !== '' && ! str_starts_with($iconColor, '#')) {
            $iconColor = '#'.$iconColor;
        }
        $sortOrder = (int) ($body['sortOrder'] ?? 0);

        // Validate dynamic fields structure (form-builder contract).
        $fields = is_array($body['fields'] ?? null) ? array_values($body['fields']) : [];
        $allowedTypes = ['text', 'number', 'textarea', 'date', 'select', 'radio', 'checkbox', 'file'];
        $clean = [];
        foreach ($fields as $f) {
            $type = (string) ($f['type'] ?? '');
            if (! in_array($type, $allowedTypes, true)) {
                return response()->json(['error' => "نوع فیلد نامعتبر است: {$type}"], 400);
            }
            $clean[] = [
                'id' => (string) ($f['id'] ?? \Illuminate\Support\Str::uuid()),
                'label' => (string) ($f['label'] ?? ''),
                'type' => $type,
                'placeholder' => (string) ($f['placeholder'] ?? ''),
                'required' => (bool) ($f['required'] ?? false),
                'options' => is_array($f['options'] ?? null) ? array_values($f['options']) : null,
                'helpText' => (string) ($f['helpText'] ?? ''),
                'defaultValue' => $f['defaultValue'] ?? null,
                'pricePerUnit' => isset($f['pricePerUnit']) ? (int) $f['pricePerUnit'] : null,
            ];
        }

        $service = ! empty($body['id']) ? Service::find($body['id']) : null;
        $payload = [
            'category_id' => (string) $body['categoryId'],
            'title' => $title,
            'description' => (string) ($body['description'] ?? ''),
            'base_price' => (int) ($body['basePrice'] ?? 0),
            'estimated_time' => (string) ($body['estimatedTime'] ?? ''),
            'icon' => (string) ($body['icon'] ?? ''),
            'icon_color' => $iconColor,
            'sort_order' => $sortOrder,
            'is_active' => (bool) ($body['isActive'] ?? true),
            'is_featured' => (bool) ($body['isFeatured'] ?? false),
            'featured_sort' => (int) ($body['featuredSort'] ?? 0),
            'fields_json' => json_encode($clean, JSON_UNESCAPED_UNICODE),
            'pricing_formula' => (string) ($body['pricingFormulaDescription'] ?? ''),
        ];

        // ── کارمزد کافی‌نت + اطلاعیه سرویس ─────────────────────────────
        // Preserve-when-absent: an older admin build (PWA service-worker
        // cache) posts bodies without these keys — saving from it must never
        // silently wipe values configured through the newer UI or by hand.
        if (array_key_exists('benefit', $body)) {
            $payload['benefit'] = $body['benefit'] === null
                ? null
                : max(0, (int) $body['benefit']);
        }
        if (array_key_exists('noticeType', $body) || array_key_exists('noticeText', $body)) {
            $noticeType = in_array($body['noticeType'] ?? null, ['lock', 'normal'], true)
                ? $body['noticeType']
                : null;
            $payload['notice_type'] = $noticeType;
            // The text only carries meaning together with a type; with no type
            // (back to the normal flow) both columns are cleared.
            $payload['notice_text'] = $noticeType
                ? trim((string) ($body['noticeText'] ?? ''))
                : null;
        }

        // URL slug (#/service/{slug}) — generated ONCE from the title and kept
        // stable afterwards so shared links never break. New rows always get
        // one; legacy rows created before the slug column get theirs on the
        // first save after the migration.
        if (! $service || trim((string) $service->slug) === '') {
            $payload['slug'] = SlugService::uniqueFor($title, $service?->id);
        }

        if ($service) {
            $service->update($payload);
        } else {
            $service = Service::create($payload + ['created_at' => now()->toIso8601String()]);
        }

        return response()->json(Formatter::service($service));
    }

    public function deleteService(Request $request, string $id): JsonResponse
    {
        Service::where('id', $id)->delete();

        return response()->json(['message' => 'خدمت حذف شد.']);
    }
}
