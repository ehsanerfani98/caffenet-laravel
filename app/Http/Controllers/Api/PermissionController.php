<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Permission catalog + role metadata (permissionsRouter).
 */
class PermissionController extends Controller
{
    public function catalog(Request $request): JsonResponse
    {
        return response()->json([
            'catalog' => PermissionService::catalog(),
            'groups' => PermissionService::groups(),
            'roleLabels' => PermissionService::ROLE_LABELS,
            'actionLabels' => PermissionService::ACTION_LABELS,
            'roleDefaults' => [
                'admin' => PermissionService::roleDefaults('admin'),
                'operator' => PermissionService::roleDefaults('operator'),
                'customer' => PermissionService::roleDefaults('customer'),
            ],
        ]);
    }
}
