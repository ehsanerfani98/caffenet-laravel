<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmsPatternVar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD for SMS pattern variable mappings.
 *
 * Each row has TWO free-text fields:
 *   var_name   = the custom name the admin used in ippanel (e.g. "order_id")
 *   data_field = the system field that fills it (e.g. "order_number")
 *
 * The admin can name variables anything they want in ippanel, then map
 * each one to a system field here. No dropdowns — both are free text.
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
            'data_field' => $r->data_field,
            'sort_order' => $r->sort_order,
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $patternKey = trim((string) $request->json('pattern_key', ''));
        $varName = trim((string) $request->json('var_name', ''));
        $dataField = trim((string) $request->json('data_field', ''));

        if ($patternKey === '' || $varName === '' || $dataField === '') {
            return response()->json(['error' => 'نام متغیر و فیلد داده الزامی است.'], 400);
        }

        $var = SmsPatternVar::create([
            'pattern_key' => $patternKey,
            'var_name' => $varName,
            'data_field' => $dataField,
            'sort_order' => (int) SmsPatternVar::where('pattern_key', $patternKey)->max('sort_order') + 1,
            'created_at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'id' => $var->id,
            'pattern_key' => $var->pattern_key,
            'var_name' => $var->var_name,
            'data_field' => $var->data_field,
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
        $dataField = trim((string) $request->json('data_field', ''));
        if ($varName !== '') $var->var_name = $varName;
        if ($dataField !== '') $var->data_field = $dataField;
        $var->save();

        return response()->json([
            'id' => $var->id,
            'pattern_key' => $var->pattern_key,
            'var_name' => $var->var_name,
            'data_field' => $var->data_field,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        SmsPatternVar::where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }
}
