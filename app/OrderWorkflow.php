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
                    $this->requireStatus($locked, OrderStatus::Pending);
                    if (! $locked->items()->exists()) {
                        throw ValidationException::withMessages(['action' => 'Add order items before starting production.']);
                    }
                    $locked->status = OrderStatus::InProduction->value;
                    break;
                case 'mark_ready':
                    $this->requireStatus($locked, OrderStatus::InProduction);
                    if (! $locked->items()->exists() || ! ($data['quality_checked'] ?? false)) {
                        throw ValidationException::withMessages(['quality_checked' => 'Confirm that every item is finished and quality checked.']);
                    }
                    $locked->status = OrderStatus::ReadyForDelivery->value;
                    break;
                case 'reopen':
                    if ($locked->status === OrderStatus::Pending->value || blank($data['notes'] ?? null)) {
                        throw ValidationException::withMessages(['notes' => 'Reopening requires a changed status and a correction reason.']);
                    }
                    $locked->status = OrderStatus::Pending->value;
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

    private function requireStatus(Order $order, OrderStatus $expected): void
    {
        if ($order->status !== $expected->value) {
            throw ValidationException::withMessages(['action' => 'This action does not match the current order status.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function fingerprint(array $data): string
    {
        ksort($data);

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }
}
