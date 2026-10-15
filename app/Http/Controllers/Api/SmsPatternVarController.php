<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmsPatternVar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD for SMS pattern variable names.
 *
 * Each row stores just the variable NAME the admin declared in ippanel
 * (e.g. "order_number", "customer_name"). The system internally knows
 * what value to fill for each name — the admin never selects a "data field".
 */
class SmsPatternVarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SmsPatternVar::orderBy('pattern_key')->orderBy('sort_order');
        if ($patternKey = $request->query('pattern_key')) {
            $query->where('pattern_key', $patternKey);
        }
        return response()->json($query->get()->map(fn ($r) => [
            'id' => $r->id,
            'pattern_key' => $r->pattern_key,
            'var_name' => $r->var_name,
            'sort_order' => $r->sort_order,
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $patternKey = trim((string) $request->json('pattern_key', ''));
        $varName = trim((string) $request->json('var_name', ''));

        if ($patternKey === '' || $varName === '') {
            return response()->json(['error' => 'نام متغیر الزامی است.'], 400);
        }

        $var = SmsPatternVar::create([
            'pattern_key' => $patternKey,
            'var_name' => $varName,
            'sort_order' => (int) SmsPatternVar::where('pattern_key', $patternKey)->max('sort_order') + 1,
            'created_at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'id' => $var->id,
            'pattern_key' => $var->pattern_key,
            'var_name' => $var->var_name,
            'sort_order' => $var->sort_order,
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $var = SmsPatternVar::find($id);
        if (! $var) {
            return response()->json(['error' => 'یافت نشد.'], 404);
        }

        $varName = trim((string) $request->json('var_name', ''));
        if ($varName !== '') $var->var_name = $varName;
        $var->save();

        return response()->json([
            'id' => $var->id,
            'pattern_key' => $var->pattern_key,
            'var_name' => $var->var_name,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        SmsPatternVar::where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }
}
