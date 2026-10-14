<?php

namespace App\Http\Controllers\Api;

use App\Contracts\QueueManagerServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-only endpoints for the live queue view.
 *
 * The admin/operator dashboard polls /api/queue every 5 seconds for the
 * current snapshot. Position-ordered, includes the order's queue_type
 * and ETA so the panel can render "in progress" + "waiting" sections.
 */
class QueueController extends Controller
{
    public function __construct(
        private readonly QueueManagerServiceInterface $queue,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        if (! in_array($user->role, ['admin', 'operator'], true)) {
            return response()->json(['error' => 'دسترسی غیرمجاز.'], 403);
        }

        $snapshot = $this->queue->snapshot();
        $waiting = $this->queue->waitingCount();

        return response()->json([
            'queue' => $snapshot,
            'waiting_count' => $waiting,
            'updated_at' => now()->toIso8601String(),
        ]);
    }
}
