<?php

namespace App;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class OrderDeliveryWorkflow
{
    public function __construct(private readonly PaymentEntries $payments) {}

    /** @param array<string, mixed> $data */
    public function confirm(Order $order, User $actor, array $data): void
    {
        $items = $order->items()->lockForUpdate()->get();
        if ($order->status !== OrderStatus::ReadyForDelivery->value || $items->isEmpty() || ! $items->every(fn (OrderItem $item): bool => $item->isReadyForDelivery())) {
            throw ValidationException::withMessages(['action' => 'Every item must be ready and the order must be Ready for delivery before confirming delivery.']);
        }
        $date = $data['delivery_date'] ?? '';
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $order->order_date->toDateString() || $date > now(config('app.reporting_timezone'))->toDateString() || blank($data['delivery_recipient'] ?? null)) {
            throw ValidationException::withMessages(['delivery_date' => 'Enter a recipient and a valid delivery date between the order date and today.']);
        }
        $order->delivery_date = $date;
        $order->delivery_recipient = $data['delivery_recipient'];
        $order->delivery_notes = $data['notes'] ?? null;
        $order->delivery_confirmed_by = $actor->id;
        $order->delivery_confirmed_by_name = $actor->name;
        $order->delivery_confirmed_at = now();
        $order->status = OrderStatus::Delivered->value;
    }

    public function complete(Order $order): void
    {
        if ($order->status !== OrderStatus::Delivered->value || $order->delivery_date === null || blank($order->delivery_recipient) || $order->delivery_confirmed_at === null || blank($order->delivery_confirmed_by_name)) {
            throw ValidationException::withMessages(['action' => 'Confirm full delivery before completing this order. Historical status alone is not delivery evidence.']);
        }
        if ($this->payments->summary($order)['balance_in_cents'] !== 0) {
            throw ValidationException::withMessages(['action' => 'Record payment for the actual remaining balance before completing this order.']);
        }
        $order->status = OrderStatus::Completed->value;
    }
}
