<?php

namespace App;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class OutstandingBalances
{
    /** @return Builder<Order> */
    public function orders(User $user): Builder
    {
        $driver = Order::query()->getConnection()->getDriverName();
        $integerType = match ($driver) {
            'mysql', 'mariadb' => 'SIGNED', 'pgsql' => 'BIGINT', default => 'INTEGER'
        };
        $quantity = "CAST(ROUND(CAST(REPLACE(REPLACE(quantity, ' pcs', ''), ' kgs', '') AS DECIMAL(12,2)) * 100, 0) AS ".$integerType.')';
        $price = 'CAST(ROUND(unit_price * 100, 0) AS '.$integerType.')';
        $product = '('.$quantity.' * '.$price.' + 50)';
        $subtotal = in_array($driver, ['mysql', 'mariadb'], true) ? '('.$product.' DIV 100)' : '('.$product.' / 100)';
        $totals = Order::query()->visibleTo($user)->select('orders.*')->addSelect([
            'total_amount_in_cents' => OrderItem::query()->selectRaw('COALESCE(SUM('.$subtotal.'), 0)')->whereColumn('order_id', 'orders.id'),
            'total_paid_in_cents' => Payment::query()->selectRaw('COALESCE(SUM(CAST(ROUND(amount * 100, 0) AS '.$integerType.')), 0)')->whereColumn('order_id', 'orders.id'),
        ]);
        $balances = Order::query()->fromSub($totals, 'orders')->select('orders.*')
            ->selectRaw('CASE WHEN total_amount_in_cents > total_paid_in_cents THEN total_amount_in_cents - total_paid_in_cents ELSE 0 END AS balance_in_cents');

        return Order::query()->fromSub($balances, 'orders')->select('orders.*')->where('balance_in_cents', '>', 0)
            ->withCasts(['balance_in_cents' => 'integer', 'total_amount_in_cents' => 'integer', 'total_paid_in_cents' => 'integer']);
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function summary(User $user, array $filters = [], int $perPage = 10): array
    {
        $today = now(config('app.reporting_timezone'))->toDateString();
        $orders = $this->orders($user);
        if (filled($filters['search'] ?? null)) {
            $orders->whereHas('customer', fn (Builder $customer): Builder => $customer->where('company_name', 'like', '%'.$filters['search'].'%'));
        }
        if (filled($filters['customer_id'] ?? null)) {
            $orders->where('customer_id', $filters['customer_id']);
        }
        match ($filters['due'] ?? '') {
            'overdue' => $orders->where('payment_due_date', '<', $today),
            'today' => $orders->where('payment_due_date', $today),
            'unscheduled' => $orders->whereNull('payment_due_date'),
            default => null,
        };
        $summary = (clone $orders)->select([])->selectRaw('COALESCE(SUM(balance_in_cents), 0) AS outstanding_in_cents')
            ->selectRaw('COUNT(*) AS orders_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN payment_due_date < ? THEN 1 ELSE 0 END), 0) AS overdue_count', [$today])->first();
        $customerTotals = (clone $orders)->select('customer_id')
            ->selectRaw('SUM(balance_in_cents) AS outstanding_in_cents')->selectRaw('COUNT(*) AS unpaid_orders_count')
            ->selectRaw('SUM(CASE WHEN payment_due_date < ? THEN 1 ELSE 0 END) AS overdue_orders_count', [$today])
            ->selectRaw('MIN(payment_due_date) AS earliest_due_date')->groupBy('customer_id');
        $customers = Customer::query()->joinSub($customerTotals, 'customer_balances', 'customers.id', '=', 'customer_balances.customer_id')
            ->select('customers.id', 'customers.company_name', 'customer_balances.outstanding_in_cents', 'customer_balances.unpaid_orders_count', 'customer_balances.overdue_orders_count')
            ->withCasts(['outstanding_in_cents' => 'integer', 'unpaid_orders_count' => 'integer', 'overdue_orders_count' => 'integer'])
            ->orderByDesc('overdue_orders_count')->orderByDesc('outstanding_in_cents')->orderBy('customers.id')
            ->paginate($perPage, ['*'], 'balance_customers_page')->withQueryString();
        $unpaidOrders = $orders->with('customer:id,company_name')
            ->orderByRaw('CASE WHEN payment_due_date < ? THEN 0 WHEN payment_due_date = ? THEN 1 ELSE 2 END', [$today, $today])
            ->orderByRaw('CASE WHEN payment_due_date IS NULL THEN 1 ELSE 0 END')->orderBy('payment_due_date')->orderByDesc('id')
            ->paginate($perPage, ['*'], 'balance_orders_page')->withQueryString();

        return [
            'outstanding_in_cents' => (int) $summary->outstanding_in_cents, 'orders_count' => (int) $summary->orders_count,
            'overdue_count' => (int) $summary->overdue_count, 'customers' => $customers, 'orders' => $unpaidOrders, 'today' => $today,
        ];
    }
}
