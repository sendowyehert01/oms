<?php

namespace App;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class OrderItemWorkflow
{
    public function __construct(private readonly OrderWorkflow $workflow) {}

    /** @param array<string, mixed> $data */
    public function update(Order $order, OrderItem $item, User $actor, array $data): Order
    {
        $data['order_item_id'] = $item->id;

        return $this->workflow->perform($order, $actor, $data, function (Order $locked, User $actor, array $data) use ($item): void {
            if (! in_array($locked->status, [OrderStatus::Pending->value, OrderStatus::InProduction->value, OrderStatus::ReadyForDelivery->value], true)) {
                throw ValidationException::withMessages(['action' => 'Reopen this order before changing its item production tracking.']);
            }
            if ($locked->status !== OrderStatus::Pending->value && $locked->item_tracking_started_at === null) {
                throw ValidationException::withMessages(['action' => 'This order has historical progress without item tracking. Reopen it with a reason before checking its items.']);
            }
            $current = $locked->items()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            switch ($data['action']) {
                case 'check_availability':
                    $this->checkAvailability($locked, $current, $actor, $data);
                    break;
                case 'check_materials':
                    $this->checkMaterials($locked, $current, $actor, $data);
                    break;
                case 'start_production':
                    if ($current->production_status !== ItemProductionStatus::Pending || ! in_array($current->stock_availability, [ItemAvailability::PartiallyAvailable, ItemAvailability::Unavailable], true)) {
                        throw ValidationException::withMessages(['action' => 'Check finished stock first. Available stock uses the allocation and quality-check action; partial or unavailable stock can start production.']);
                    }
                    if (! $current->hasAvailableMaterials()) {
                        throw ValidationException::withMessages(['action' => 'Check raw materials and confirm every required quantity is available before starting production.']);
                    }
                    $previous = $current->production_status->value;
                    $current->production_status = ItemProductionStatus::InProduction;
                    $current->readiness_source = 'production';
                    $this->workflow->record($locked, $actor, $data, 'item_production_changed', $previous, $current->production_status->value, $current);
                    break;
                case 'mark_ready':
                    $this->markReady($locked, $current, $actor, $data);
                    break;
                default:
                    throw ValidationException::withMessages(['action' => 'This item action is unavailable.']);
            }
            $current->save();
            $locked->item_tracking_started_at ??= now();
            $this->aggregateProgress($locked, $actor, $data);
        }, ability: 'updateProduction');
    }

    /** @param array<string, mixed> $data */
    private function checkAvailability(Order $order, OrderItem $item, User $actor, array $data): void
    {
        $availability = ItemAvailability::from($data['stock_availability']);
        if ($availability === ItemAvailability::NotChecked) {
            throw ValidationException::withMessages(['stock_availability' => 'Choose the result of the finished-stock check.']);
        }
        $previous = $item->stock_availability->value;
        $countedQuantity = isset($data['finished_stock_quantity']) ? (string) $data['finished_stock_quantity'] : null;
        if ($countedQuantity !== null) {
            $counted = ItemMeasurement::fromInput($countedQuantity, $item->unit)->hundredths;
            $ordered = $item->measurement()->hundredths;
            $countedAvailability = $counted >= $ordered ? ItemAvailability::Available : ($counted > 0 ? ItemAvailability::PartiallyAvailable : ItemAvailability::Unavailable);
            if ($availability !== $countedAvailability) {
                throw ValidationException::withMessages(['finished_stock_quantity' => 'The counted finished quantity must match the selected availability result.']);
            }
        }
        if ($item->production_status === ItemProductionStatus::Pending && ($previous !== $availability->value || $item->finished_stock_quantity !== $countedQuantity)) {
            if ($item->material_availability !== ItemAvailability::NotChecked) {
                $this->workflow->record($order, $actor, $data, 'materials_reset', $item->material_availability->value, ItemAvailability::NotChecked->value, $item, additional: true);
            }
            $item->material_checklist = null;
            $item->material_availability = ItemAvailability::NotChecked;
            $item->material_notes = null;
            $item->materials_checked_by = null;
            $item->materials_checked_by_name = null;
            $item->materials_checked_at = null;
        }
        $item->stock_availability = $availability;
        $item->finished_stock_quantity = $countedQuantity;
        $item->availability_notes = $data['notes'] ?? null;
        $item->availability_checked_by = $actor->id;
        $item->availability_checked_by_name = $actor->name;
        $item->availability_checked_at = now();
        if ($item->production_status === ItemProductionStatus::ReadyForDelivery && $availability !== ItemAvailability::Available) {
            $item->ready_check_required = true;
        }
        $eventNotes = $countedQuantity === null ? ($data['notes'] ?? null) : 'Finished stock counted: '.$countedQuantity.' '.$item->unit.'.'.(filled($data['notes'] ?? null) ? "\n".$data['notes'] : '');
        $this->workflow->record($order, $actor, $data, 'availability_checked', $previous, $availability->value, $item, eventNotes: $eventNotes);
    }

    /** @param array<string, mixed> $data */
    private function checkMaterials(Order $order, OrderItem $item, User $actor, array $data): void
    {
        if (! $item->needsMaterialCheck()) {
            throw ValidationException::withMessages(['action' => 'Check raw materials for pending items that need production. Reopen an item with an administrator before changing materials after production starts.']);
        }

        $availability = MaterialChecklist::availability($data['materials']);
        $previous = $item->material_availability->value;
        $item->material_checklist = $data['materials'];
        $item->material_availability = $availability;
        $item->material_notes = $data['notes'] ?? null;
        $item->materials_checked_by = $actor->id;
        $item->materials_checked_by_name = $actor->name;
        $item->materials_checked_at = now();
        $this->workflow->record($order, $actor, $data, 'materials_checked', $previous, $availability->value, $item, eventNotes: MaterialChecklist::historyNotes($data['materials'], $data['notes'] ?? null));
    }

    /** @param array<string, mixed> $data */
    private function markReady(Order $order, OrderItem $item, User $actor, array $data): void
    {
        if (! ($data['quality_checked'] ?? false)) {
            throw ValidationException::withMessages(['quality_checked' => 'Confirm the full ordered quantity is finished or allocated and quality checked.']);
        }
        $previous = $item->production_status->value;
        if ($item->production_status === ItemProductionStatus::InProduction) {
            $item->readiness_source = 'production';
        } elseif (($item->production_status === ItemProductionStatus::Pending || $item->ready_check_required) && $item->stock_availability === ItemAvailability::Available) {
            $item->readiness_source = 'stock';
        } else {
            throw ValidationException::withMessages(['action' => 'Allocate available finished stock or finish production before confirming readiness.']);
        }
        $item->production_status = ItemProductionStatus::ReadyForDelivery;
        $item->ready_check_required = false;
        $item->ready_confirmed_by = $actor->id;
        $item->ready_confirmed_by_name = $actor->name;
        $item->ready_confirmed_at = now();
        $this->workflow->record($order, $actor, $data, $previous === $item->production_status->value ? 'readiness_reconfirmed' : 'item_production_changed', $previous, $item->production_status->value, $item);
    }

    /** @param array<string, mixed> $data */
    private function aggregateProgress(Order $order, User $actor, array $data): void
    {
        $items = $order->items()->get();
        $allReady = $items->isNotEmpty() && $items->every(fn (OrderItem $item): bool => $item->isReadyForDelivery());
        $someProgress = $items->contains(fn (OrderItem $item): bool => $item->production_status !== ItemProductionStatus::Pending);
        $next = $allReady ? OrderStatus::ReadyForDelivery : ($someProgress ? OrderStatus::InProduction : OrderStatus::Pending);
        $rank = [OrderStatus::Pending->value => 0, OrderStatus::InProduction->value => 1, OrderStatus::ReadyForDelivery->value => 2];
        if ($rank[$next->value] > $rank[$order->status]) {
            $previous = $order->status;
            $order->status = $next->value;
            $this->workflow->record($order, $actor, $data, 'order_status_changed', $previous, $order->status, additional: true);
        }
    }
}
