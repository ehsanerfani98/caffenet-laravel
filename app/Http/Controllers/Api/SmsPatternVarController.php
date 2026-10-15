<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmsPatternVar;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD for SMS pattern variable mappings.
 *
 * The admin creates patterns in ippanel with custom variable names,
 * then maps each variable to a system data field here.
 *
 * GET    /api/sms/pattern-vars              → list all (grouped by pattern_key)
 * GET    /api/sms/pattern-vars/{patternKey} → list vars for one pattern
 * POST   /api/sms/pattern-vars              → {pattern_key, var_name, data_field} → create
 * PUT    /api/sms/pattern-vars/{id}         → update one var
 * DELETE /api/sms/pattern-vars/{id}         → delete one var
 * GET    /api/sms/data-fields               → list available system data fields
 */
class SmsPatternVarController extends Controller
{
    /** List all variable mappings, optionally filtered by pattern_key. */
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

    /** List available system data fields the admin can map to. */
    public function dataFields(): JsonResponse
    {
        return response()->json(
            collect(SmsService::DATA_FIELDS)->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
            ])->values()
        );
    }

    /** Create a new variable mapping. */
    public function store(Request $request): JsonResponse
    {
        $patternKey = trim((string) $request->json('pattern_key', ''));
        $varName = trim((string) $request->json('var_name', ''));
        $dataField = trim((string) $request->json('data_field', ''));

        if ($patternKey === '' || $varName === '' || $dataField === '') {
            return response()->json(['error' => 'همه فیلدها الزامی است.'], 400);
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

    /** Update a variable mapping. */
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

    /** Delete a variable mapping. */
    public function destroy(string $id): JsonResponse
    {
        SmsPatternVar::where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }
}
