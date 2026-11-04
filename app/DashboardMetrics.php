<?php

namespace App;

use App\Models\Customer;
use App\Models\Order;

class DashboardMetrics
{
    /**
     * @return array{clients: array{total: int, with_orders: int, without_orders: int}, orders: int, statuses: list<array{label: string, count: int}>}
     */
    public function summary(): array
    {
        $clientsWithOrders = Customer::query()->has('orders')->count();
        $clientsWithoutOrders = Customer::query()->doesntHave('orders')->count();

        $statusCases = [];
        $statusBindings = [];

        foreach (OrderEntries::STATUSES as $status) {
            $statusCases[] = 'WHEN status = ? THEN ?';
            $statusBindings[] = $status;
            $statusBindings[] = $status;
        }

        $statusBindings[] = 'Other status';
        $counts = Order::query()
            ->selectRaw('CASE '.implode(' ', $statusCases).' ELSE ? END AS dashboard_status', $statusBindings)
            ->selectRaw('COUNT(*) AS order_count')
            ->groupBy('dashboard_status')
            ->pluck('order_count', 'dashboard_status');

        $statuses = array_map(fn (string $status): array => [
            'label' => $status,
            'count' => (int) $counts->get($status, 0),
        ], OrderEntries::STATUSES);

        if ($counts->has('Other status')) {
            $statuses[] = ['label' => 'Other status', 'count' => (int) $counts->get('Other status')];
        }

        return [
            'clients' => [
                'total' => $clientsWithOrders + $clientsWithoutOrders,
                'with_orders' => $clientsWithOrders,
                'without_orders' => $clientsWithoutOrders,
            ],
            'orders' => (int) $counts->sum(),
            'statuses' => $statuses,
        ];
    }
}
