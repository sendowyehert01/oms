<?php

namespace App;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderWorkflow
{
    /** @param array<string, mixed> $data */
    public function update(Order $order, User $actor, array $data): Order
    {
        return $this->perform($order, $actor, $data, function (Order $locked, User $actor, array $data): void {
            $previous = $locked->status;
            switch ($data['action']) {
                case 'start_production':
                case 'mark_ready':
                    throw ValidationException::withMessages(['action' => 'Use the per-item availability and production controls to update this order.']);
                case 'reopen':
                    if ($locked->status === OrderStatus::Pending->value || blank($data['notes'] ?? null)) {
                        throw ValidationException::withMessages(['notes' => 'Reopening requires a changed status and a correction reason.']);
                    }
                    $locked->status = OrderStatus::Pending->value;
                    $this->reopenItems($locked, $actor, $data);
                    $locked->item_tracking_started_at = null;
                    break;
                default:
                    throw ValidationException::withMessages(['action' => 'This status action is unavailable.']);
            }
            $this->record($locked, $actor, $data, $data['action'] === 'reopen' ? 'order_reopened' : 'order_status_changed', $previous, $locked->status);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Closure(Order, User, array<string, mixed>): void  $operation
     */
    public function perform(Order $order, User $actor, array $data, Closure $operation): Order
    {
        return DB::transaction(function () use ($order, $actor, $data, $operation): Order {
            $locked = Order::query()->visibleTo($actor)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('updateStatus', $locked);
            $previous = OrderStatusHistory::query()->where('submission_key', $data['submission_key'])->first();
            if ($previous !== null) {
                if ($previous->order_id !== $locked->id || $previous->actor_id !== $actor->id || $previous->request_hash !== $this->fingerprint($data)) {
                    throw ValidationException::withMessages(['submission_key' => 'This form was already used for another change. Refresh the order.']);
                }

                return $locked;
            }
            if ($locked->workflow_version !== (int) $data['workflow_version']) {
                throw ValidationException::withMessages(['workflow_version' => 'This order changed after you opened the form. Refresh the page and review its latest progress.']);
            }
            $operation($locked, $actor, $data);
            $locked->workflow_version++;
            $locked->save();

            return $locked;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data */
    public function record(Order $order, User $actor, array $data, string $eventType, ?string $from, string $to, ?OrderItem $item = null, bool $additional = false): OrderStatusHistory
    {
        return $order->statusHistory()->create([
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'order_item_id' => $item?->id,
            'item_name' => $item?->product_name,
            'event_type' => $eventType,
            'from_status' => $from,
            'to_status' => $to,
            'notes' => $data['notes'] ?? null,
            'submission_key' => $additional ? (string) Str::uuid() : $data['submission_key'],
            'request_hash' => $this->fingerprint($data),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function reopenItems(Order $order, User $actor, array $data): void
    {
        foreach ($order->items()->orderBy('id')->lockForUpdate()->get() as $item) {
            if ($item->stock_availability !== ItemAvailability::NotChecked) {
                $this->record($order, $actor, $data, 'availability_reset', $item->stock_availability->value, ItemAvailability::NotChecked->value, $item, additional: true);
            }
            if ($item->production_status !== ItemProductionStatus::Pending) {
                $this->record($order, $actor, $data, 'item_reopened', $item->production_status->value, ItemProductionStatus::Pending->value, $item, additional: true);
            }
            $item->stock_availability = ItemAvailability::NotChecked;
            $item->production_status = ItemProductionStatus::Pending;
            $item->availability_notes = null;
            $item->availability_checked_by = null;
            $item->availability_checked_by_name = null;
            $item->availability_checked_at = null;
            $item->readiness_source = null;
            $item->ready_check_required = false;
            $item->ready_confirmed_by = null;
            $item->ready_confirmed_by_name = null;
            $item->ready_confirmed_at = null;
            $item->save();
        }
    }

    /** @param array<string, mixed> $data */
    private function fingerprint(array $data): string
    {
        ksort($data);

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }
}
