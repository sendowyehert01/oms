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
    public function __construct(private readonly OrderDeliveryWorkflow $delivery, private readonly AuditRecorder $audit) {}

    /** @param array<string, mixed> $data */
    public function update(Order $order, User $actor, array $data): Order
    {
        return $this->perform($order, $actor, $data, function (Order $locked, User $actor, array $data): void {
            $previous = $locked->status;
            $eventNotes = null;
            switch ($data['action']) {
                case 'deliver':
                    $this->delivery->confirm($locked, $actor, $data);
                    $eventNotes = 'Full delivery on '.$data['delivery_date'].' received by '.$data['delivery_recipient'].(filled($data['notes'] ?? null) ? "\n".$data['notes'] : '');
                    break;
                case 'complete':
                    $this->delivery->complete($locked);
                    break;
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
                    $locked->delivery_date = null;
                    $locked->delivery_recipient = null;
                    $locked->delivery_notes = null;
                    $locked->delivery_confirmed_by = null;
                    $locked->delivery_confirmed_by_name = null;
                    $locked->delivery_confirmed_at = null;
                    break;
                default:
                    throw ValidationException::withMessages(['action' => 'This status action is unavailable.']);
            }
            $eventType = match ($data['action']) {
                'reopen' => 'order_reopened', 'deliver' => 'delivery_confirmed', 'complete' => 'order_completed', default => 'order_status_changed',
            };
            $this->record($locked, $actor, $data, $eventType, $previous, $locked->status, eventNotes: $eventNotes);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Closure(Order, User, array<string, mixed>): void  $operation
     */
    public function perform(Order $order, User $actor, array $data, Closure $operation, string $ability = 'updateStatus'): Order
    {
        return DB::transaction(function () use ($order, $actor, $data, $operation, $ability): Order {
            $locked = Order::query()->visibleTo($actor)->whereKey($order->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize($ability, $locked);
            $previous = OrderStatusHistory::query()->where('submission_key', $data['submission_key'])->first();
            if ($previous !== null) {
                if ($previous->order_id !== $locked->id || $previous->actor_id !== $actor->id || $previous->request_hash !== $this->fingerprint($data)) {
                    throw ValidationException::withMessages(['submission_key' => 'This form was already used for another change. Refresh the order checklist.']);
                }

                return $locked;
            }
            if ($locked->workflow_version !== (int) $data['workflow_version']) {
                throw ValidationException::withMessages(['workflow_version' => 'This order changed after you opened the form. Refresh the order checklist and review its latest progress.']);
            }
            $operation($locked, $actor, $data);
            $locked->workflow_version++;
            $locked->save();

            return $locked;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data */
    public function record(Order $order, User $actor, array $data, string $eventType, ?string $from, string $to, ?OrderItem $item = null, bool $additional = false, ?string $eventNotes = null): OrderStatusHistory
    {
        $history = $order->statusHistory()->create([
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'order_item_id' => $item?->id,
            'item_name' => $item?->product_name,
            'event_type' => $eventType,
            'from_status' => $from,
            'to_status' => $to,
            'notes' => $eventNotes ?? $data['notes'] ?? null,
            'submission_key' => $additional ? (string) Str::uuid() : $data['submission_key'],
            'request_hash' => $this->fingerprint($data),
        ]);

        if (! $additional) {
            $this->audit->workflowRecorded($order, $actor, $eventType, $to, $item);
        }

        return $history;
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
