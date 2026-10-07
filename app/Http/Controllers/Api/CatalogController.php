<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Support\Formatter;
use App\Models\Category;
use App\Models\Service;
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
        return response()->json(Category::orderBy('created_at')->get()->map([Formatter::class, 'category'])->values()->all());
    }

    public function saveCategory(Request $request): JsonResponse
    {
        $body = (array) $request->json()->all();
        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            return response()->json(['error' => 'عنوان دسته‌بندی الزامی است.'], 400);
        }

        $category = $body['id'] ? Category::find($body['id']) : null;
        if ($category) {
            $category->update([
                'title' => $title,
                'icon_name' => (string) ($body['iconName'] ?? $category->icon_name),
                'description' => (string) ($body['description'] ?? ''),
            ]);
        } else {
            $category = Category::create([
                'title' => $title,
                'icon_name' => (string) ($body['iconName'] ?? 'Grid3x3'),
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
        $rows = $query->orderBy('created_at')->get();

        return response()->json($rows->map([Formatter::class, 'service'])->values()->all());
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
            'is_active' => (bool) ($body['isActive'] ?? true),
            'fields_json' => json_encode($clean, JSON_UNESCAPED_UNICODE),
            'pricing_formula' => (string) ($body['pricingFormulaDescription'] ?? ''),
        ];

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
