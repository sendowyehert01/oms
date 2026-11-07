<?php

namespace App;

enum AuditAction: string
{
    case TabSwitched = 'tab_switched';
    case ClientCreated = 'client_created';
    case OrderCreated = 'order_created';
    case OrderViewed = 'order_viewed';
    case ReceiptViewed = 'receipt_viewed';
    case DeliveryTargetUpdated = 'delivery_target_updated';
    case PaymentDueDateUpdated = 'payment_due_date_updated';
    case AvailabilityChecked = 'availability_checked';
    case MaterialsChecked = 'materials_checked';
    case ProductionStarted = 'production_started';
    case ReadinessConfirmed = 'readiness_confirmed';
    case ReadinessReconfirmed = 'readiness_reconfirmed';
    case DeliveryConfirmed = 'delivery_confirmed';
    case OrderCompleted = 'order_completed';
    case OrderReopened = 'order_reopened';
    case PaymentRecorded = 'payment_recorded';
    case OrderDeleted = 'order_deleted';
    case StaffCreated = 'staff_created';
    case SignedIn = 'signed_in';
    case SignedOut = 'signed_out';
    case PasswordChanged = 'password_changed';

    public function label(): string
    {
        return match ($this) {
            self::TabSwitched => 'Switch tab',
            self::ClientCreated => 'Create client',
            self::OrderCreated => 'Create order',
            self::OrderViewed => 'View order',
            self::ReceiptViewed => 'View receipt',
            self::DeliveryTargetUpdated => 'Update delivery target',
            self::PaymentDueDateUpdated => 'Update payment due date',
            self::AvailabilityChecked => 'Check stock availability',
            self::MaterialsChecked => 'Check raw materials',
            self::ProductionStarted => 'Start production',
            self::ReadinessConfirmed => 'Confirm readiness',
            self::ReadinessReconfirmed => 'Reconfirm readiness',
            self::DeliveryConfirmed => 'Confirm delivery',
            self::OrderCompleted => 'Complete order',
            self::OrderReopened => 'Reopen order',
            self::PaymentRecorded => 'Record payment',
            self::OrderDeleted => 'Delete order',
            self::StaffCreated => 'Create staff account',
            self::SignedIn => 'Sign in',
            self::SignedOut => 'Sign out',
            self::PasswordChanged => 'Change password',
        };
    }

    public function verb(): string
    {
        return match ($this) {
            self::TabSwitched => 'switches to',
            self::ClientCreated, self::OrderCreated, self::StaffCreated => 'creates',
            self::OrderViewed, self::ReceiptViewed => 'views',
            self::DeliveryTargetUpdated, self::PaymentDueDateUpdated => 'updates',
            self::AvailabilityChecked, self::MaterialsChecked => 'checks',
            self::ProductionStarted => 'starts',
            self::ReadinessConfirmed, self::DeliveryConfirmed => 'confirms',
            self::ReadinessReconfirmed => 'reconfirms',
            self::OrderCompleted => 'completes',
            self::OrderReopened => 'reopens',
            self::PaymentRecorded => 'records',
            self::OrderDeleted => 'deletes',
            self::SignedIn => 'signs in to',
            self::SignedOut => 'signs out of',
            self::PasswordChanged => 'changes',
        };
    }
}
