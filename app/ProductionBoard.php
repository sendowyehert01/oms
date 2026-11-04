<?php

namespace App;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ProductionBoard
{
    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function summary(User $user, array $filters): array
    {
        $visible = Order::query()->visibleTo($user);
        $query = clone $visible;
        if (filled($filters['search'] ?? null)) {
            $search = trim($filters['search']);
            $id = preg_match('/^(?:ORD-\d{4}-)?0*(\d+)$/i', $search, $matches) ? (int) $matches[1] : null;
            $query->where(function (Builder $query) use ($search, $id): void {
                $query->whereHas('customer', fn (Builder $customer): Builder => $customer->where('company_name', 'like', '%'.$search.'%'));
                if ($id !== null) {
                    $query->orWhereKey($id);
                }
            });
        }
        foreach (['status', 'customer_id', 'created_by'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }
        if (filled($filters['target_delivery_date'] ?? null)) {
            $query->whereDate('target_delivery_date', $filters['target_delivery_date']);
        }
        $today = now(config('app.reporting_timezone'))->toDateString();
        if (($filters['late'] ?? false)) {
            $query->where('target_delivery_date', '<', $today)->whereNotIn('status', ['Delivered', 'Completed']);
        }
        $counts = (clone $query)->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $orders = $query->select('orders.*')->with(['customer:id,company_name', 'creator:id,name'])
            ->withCount([
                'items',
                'items as ready_items_count' => fn (Builder $items): Builder => $items->where('production_status', 'Ready for delivery')->where('ready_check_required', false),
                'items as unchecked_items_count' => fn (Builder $items): Builder => $items->where('stock_availability', 'Not checked'),
                'items as review_items_count' => fn (Builder $items): Builder => $items->where('ready_check_required', true),
                'items as pending_items_count' => fn (Builder $items): Builder => $items->where('production_status', 'Pending'),
            ])
            ->addSelect(['stage_started_at' => OrderStatusHistory::query()->select('created_at')
                ->whereColumn('order_id', 'orders.id')->whereColumn('to_status', 'orders.status')
                ->whereNull('order_item_id')->whereIn('event_type', ['order_created', 'order_status_changed', 'order_reopened', 'delivery_confirmed', 'order_completed'])
                ->orderByDesc('id')->limit(1)])
            ->orderByRaw('CASE WHEN target_delivery_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('target_delivery_date')->orderByDesc('id')->paginate(20)->withQueryString();

        return [
            'orders' => $orders, 'counts' => $counts, 'today' => $today,
            'statuses' => array_column(OrderStatus::cases(), 'value'),
            'customers' => Customer::query()->whereIn('id', (clone $visible)->select('customer_id'))
                ->when(filled($filters['customer_search'] ?? null), fn (Builder $customers): Builder => $customers->where('company_name', 'like', '%'.$filters['customer_search'].'%'))
                ->orderBy('company_name')->limit(50)->get(['id', 'company_name']),
            'creators' => User::query()->whereIn('id', (clone $visible)->select('created_by'))
                ->when(filled($filters['creator_search'] ?? null), fn (Builder $creators): Builder => $creators->where('name', 'like', '%'.$filters['creator_search'].'%'))
                ->orderBy('name')->limit(50)->get(['id', 'name']),
        ];
    }

    /** @return list<string> */
    public function blockers(Order $order): array
    {
        if (in_array($order->status, ['Delivered', 'Completed'], true)) {
            return [];
        }
        $blockers = [];
        if ($order->items_count === 0) {
            $blockers[] = 'No items recorded';
        }
        if ($order->status !== 'Pending' && $order->item_tracking_started_at === null) {
            $blockers[] = 'Historical progress requires review';
        }
        if ($order->review_items_count > 0) {
            $blockers[] = 'Readiness recheck required';
        }
        if ($order->unchecked_items_count > 0) {
            $blockers[] = 'Finished-stock check needed';
        }
        if ($order->pending_items_count > 0) {
            $blockers[] = 'Waiting for preparation';
        }

        return $blockers;
    }
}
