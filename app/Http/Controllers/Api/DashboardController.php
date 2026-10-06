<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard stats — customer vs staff variants, matching dashboardRouter.
 * Staff: revenue today/7d/30d, order counts, top services, daily trend.
 * Customer: own order counts by status + wallet + totals.
 */
class DashboardController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $user = $request->attributes->get('authUser');
        $isStaff = $user->role === 'admin' || $user->role === 'operator';

        $paid = array_merge(Order::NON_QUEUE_STATUSES, [Order::STATUS_CANCELLED]);

        if ($isStaff) {
            $queueCount = Order::whereNotIn('status', Order::NON_QUEUE_STATUSES)->count();
            $revenueToday = $this->revenue(now()->startOfDay()->toIso8601String());
            $revenue7 = $this->revenue(now()->subDays(7)->toIso8601String());
            $revenue30 = $this->revenue(now()->subDays(30)->toIso8601String());
            $customers = User::where('role', 'customer')->count();

            $topServices = Order::selectRaw('service_title, COUNT(*) as c, SUM(total_price) as revenue')
                ->where('status', Order::STATUS_DELIVERED)
                ->groupBy('service_title')
                ->orderByDesc('c')
                ->limit(5)
                ->get()
                ->map(fn ($r) => ['title' => $r->service_title, 'count' => (int) $r->c, 'revenue' => (int) $r->revenue])
                ->values()
                ->all();

            $trend = [];
            for ($i = 13; $i >= 0; $i--) {
                $day = now()->subDays($i);
                $start = $day->copy()->startOfDay()->toIso8601String();
                $end = $day->copy()->endOfDay()->toIso8601String();
                $trend[] = [
                    'date' => $day->toDateString(),
                    'orders' => Order::whereBetween('created_at', [$start, $end])->count(),
                    'revenue' => $this->revenue($start, $end),
                ];
            }

            return response()->json([
                'scope' => 'staff',
                'queueCount' => $queueCount,
                'revenueToday' => $revenueToday,
                'revenue7' => $revenue7,
                'revenue30' => $revenue30,
                'customers' => $customers,
                'topServices' => $topServices,
                'trend' => $trend,
            ]);
        }

        $own = Order::where('customer_id', $user->id);
        $counts = [];
        foreach (Order::STATUSES as $status) {
            $counts[$status] = (clone $own)->where('status', $status)->count();
        }

        return response()->json([
            'scope' => 'customer',
            'totalOrders' => (clone $own)->count(),
            'totalSpent' => (clone $own)->where('status', Order::STATUS_DELIVERED)->sum('paid_amount'),
            'statusCounts' => $counts,
            'walletBalance' => (int) $user->wallet_balance,
        ]);
    }

    private function revenue(string $from, ?string $to = null): int
    {
        $q = Order::where('status', Order::STATUS_DELIVERED)->where('created_at', '>=', $from);
        if ($to) {
            $q->where('created_at', '<', $to);
        }

        return (int) $q->sum('paid_amount');
    }
}
