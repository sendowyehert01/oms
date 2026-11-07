<?php

namespace App;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;

class AuditRecorder
{
    public function clientCreated(User $actor, Customer $client): AuditLog
    {
        return $this->record($actor, AuditAction::ClientCreated, 'a client "'.$client->company_name.'"', 'client', $client->id, $client->company_name);
    }

    public function orderCreated(User $actor, Order $order): AuditLog
    {
        return $this->recordOrder($actor, AuditAction::OrderCreated, $order);
    }

    public function orderDeleted(User $actor, Order $order): AuditLog
    {
        return $this->recordOrder($actor, AuditAction::OrderDeleted, $order);
    }

    public function orderViewed(User $actor, Order $order): AuditLog
    {
        return $this->recordOrder($actor, AuditAction::OrderViewed, $order);
    }

    public function receiptViewed(User $actor, Order $order, Payment $payment): AuditLog
    {
        return $this->record($actor, AuditAction::ReceiptViewed, 'a payment receipt for an order "'.$this->orderNumber($order).'"', 'payment', $payment->id, 'Payment #'.$payment->id, $order);
    }

    public function paymentRecorded(User $actor, Order $order, Payment $payment): AuditLog
    {
        $amount = number_format(MoneyAmount::inCents($payment->amount) / 100, 2);
        $type = Payment::TYPES[$payment->payment_type];

        return $this->record($actor, AuditAction::PaymentRecorded, 'a payment of PHP '.$amount.' ('.$type.') for an order "'.$this->orderNumber($order).'"', 'payment', $payment->id, 'Payment #'.$payment->id, $order);
    }

    public function tabSwitched(User $actor, string $tab): AuditLog
    {
        return $this->record($actor, AuditAction::TabSwitched, 'the "'.$tab.'" tab', 'tab', null, $tab);
    }

    public function staffCreated(User $actor, User $staff): AuditLog
    {
        $accountType = $staff->isProductionMonitor() ? 'a Production Monitor account' : 'a staff account';

        return $this->record($actor, AuditAction::StaffCreated, $accountType.' "'.$staff->name.'"', 'user', $staff->id, $staff->name);
    }

    public function signedIn(User $actor): AuditLog
    {
        return $this->record($actor, AuditAction::SignedIn, 'OMS', 'session', null, 'OMS');
    }

    public function signedOut(User $actor): AuditLog
    {
        return $this->record($actor, AuditAction::SignedOut, 'OMS', 'session', null, 'OMS');
    }

    public function passwordChanged(User $actor): AuditLog
    {
        return $this->record($actor, AuditAction::PasswordChanged, 'their password', 'user', $actor->id, $actor->name);
    }

    public function workflowRecorded(Order $order, User $actor, string $eventType, string $to, ?OrderItem $item): ?AuditLog
    {
        $action = match ($eventType) {
            'availability_checked' => AuditAction::AvailabilityChecked,
            'materials_checked' => AuditAction::MaterialsChecked,
            'item_production_changed' => match ($to) {
                ItemProductionStatus::InProduction->value => AuditAction::ProductionStarted,
                ItemProductionStatus::ReadyForDelivery->value => AuditAction::ReadinessConfirmed,
                default => null,
            },
            'readiness_reconfirmed' => AuditAction::ReadinessReconfirmed,
            'delivery_confirmed' => AuditAction::DeliveryConfirmed,
            'order_completed' => AuditAction::OrderCompleted,
            'order_reopened' => AuditAction::OrderReopened,
            'delivery_scheduled' => $order->isDirty('target_delivery_date') ? AuditAction::DeliveryTargetUpdated : null,
            'payment_due_date_changed' => $order->isDirty('payment_due_date') ? AuditAction::PaymentDueDateUpdated : null,
            default => null,
        };

        if ($action === null) {
            return null;
        }

        $number = $this->orderNumber($order);
        $itemReference = 'item "'.$item?->product_name.'" in order "'.$number.'"';
        $details = match ($action) {
            AuditAction::AvailabilityChecked => 'stock availability for '.$itemReference.' as "'.$to.'"',
            AuditAction::MaterialsChecked => 'raw materials for '.$itemReference.' as "'.$to.'"',
            AuditAction::ProductionStarted => 'production for '.$itemReference,
            AuditAction::ReadinessConfirmed, AuditAction::ReadinessReconfirmed => 'readiness for '.$itemReference.' after quality checks',
            AuditAction::DeliveryConfirmed => 'delivery of an order "'.$number.'" on '.$order->delivery_date?->toDateString().' received by "'.$order->delivery_recipient.'"',
            AuditAction::DeliveryTargetUpdated => 'the delivery target for an order "'.$number.'" from "'.($order->getOriginal('target_delivery_date')?->toDateString() ?? 'Not scheduled').'" to "'.($order->target_delivery_date?->toDateString() ?? 'Not scheduled').'"',
            AuditAction::PaymentDueDateUpdated => 'the payment due date for an order "'.$number.'" from "'.($order->getOriginal('payment_due_date')?->toDateString() ?? 'No due date').'" to "'.($order->payment_due_date?->toDateString() ?? 'No due date').'"',
            default => 'an order "'.$number.'"',
        };

        return $this->record($actor, $action, $details, $item === null ? 'order' : 'order_item', $item?->id ?? $order->id, $item?->product_name ?? $number, $order);
    }

    private function recordOrder(User $actor, AuditAction $action, Order $order): AuditLog
    {
        $number = $this->orderNumber($order);

        return $this->record($actor, $action, 'an order "'.$number.'"', 'order', $order->id, $number, $order);
    }

    private function orderNumber(Order $order): string
    {
        return app(OrderEntries::class)->number($order->id, $order->order_date->toDateString());
    }

    private function record(User $actor, AuditAction $action, string $details, string $subjectType, ?int $subjectId, string $subjectName, ?Order $order = null): AuditLog
    {
        return AuditLog::query()->create([
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'actor_role' => $actor->role,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_name' => $subjectName,
            'order_id' => $order?->id,
            'order_number' => $order === null ? null : $this->orderNumber($order),
            'statement' => $actor->name.' '.$action->verb().' '.$details.'.',
        ]);
    }
}
