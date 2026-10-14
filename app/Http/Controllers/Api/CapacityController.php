<?php

namespace App\Http\Controllers\Api;

use App\Contracts\SlotReservationServiceInterface;
use App\Contracts\StatusResolverServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\CapacityResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Public capacity endpoints.
 *
 *  - GET /api/capacity/current  → global tier + ETA (cached 30s on DB cache)
 *  - GET /api/capacity/slots    → list of open slots (auth required)
 *
 * The current endpoint is polled by every visitor's CapacityIndicator
 * widget, so it must be cheap: the snapshot is computed once and cached
 * in the database cache (Cache::store('database')) for 30 seconds.
 */
class CapacityController extends Controller
{
    public function __construct(
        private readonly StatusResolverServiceInterface $resolver,
        private readonly SlotReservationServiceInterface $slots,
    ) {}

    public function current(): JsonResponse
    {
        $snapshot = Cache::store('database')->remember(
            'capacity:current',
            30,
            fn () => $this->resolver->snapshot(),
        );

        return (new CapacityResource($snapshot))
            ->response()
            ->header('Cache-Control', 'public, max-age=30');
    }

    public function slots(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        if (! $user) {
            return response()->json(['error' => 'برای مشاهده نوبت‌ها باید وارد شوید.'], 401);
        }

        $withinHours = (int) $request->query('hours', 48);
        $withinHours = max(1, min(168, $withinHours)); // clamp 1h..7d

        $slots = $this->slots->listOpenSlots(withinHours: $withinHours);

        return response()->json([
            'slots' => $slots->map(fn ($s) => [
                'id' => $s->id,
                'operator_id' => $s->operator_id,
                'operator_name' => $s->operator?->display_name,
                'start_at' => $s->start_at,
                'end_at' => $s->end_at,
                'status' => $s->status,
            ])->values()->all(),
        ]);
    }
}
