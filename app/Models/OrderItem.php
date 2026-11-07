<?php

namespace App\Models;

use App\ItemAvailability;
use App\ItemMeasurement;
use App\ItemProductionStatus;
use App\MaterialChecklist;
use App\MoneyAmount;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'product_name', 'size', 'customization_notes', 'material_color', 'print_design_colors', 'sealing', 'sealing_other', 'puncher', 'puncher_other', 'quantity', 'unit', 'unit_price'])]
class OrderItem extends Model
{
    public const int MAX_QUANTITY = 100_000_000;

    public const array MEASUREMENT_UNITS = ['pcs', 'kgs'];

    public const array SEALING_OPTIONS = ['Bottom Seal', 'Top Seal', 'Side Seal', 'Sando Bag', 'Others'];

    public const array PUNCHER_OPTIONS = ['Sealing', 'Sando Bag', 'Notion Bag', 'Punch Hole', 'None', 'Others'];

    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function measurement(): ItemMeasurement
    {
        return ItemMeasurement::fromStored($this->quantity, $this->unit ?? 'pcs');
    }

    public function subtotalInCents(): int
    {
        return $this->measurement()->subtotalInCents(MoneyAmount::inCents($this->unit_price));
    }

    public function isReadyForDelivery(): bool
    {
        return $this->production_status === ItemProductionStatus::ReadyForDelivery && ! $this->ready_check_required;
    }

    public function hasAvailableMaterials(): bool
    {
        return $this->materials_checked_at !== null
            && $this->material_availability === ItemAvailability::Available
            && MaterialChecklist::availability($this->material_checklist ?? []) === ItemAvailability::Available;
    }

    public function needsMaterialCheck(): bool
    {
        return $this->production_status === ItemProductionStatus::Pending
            && in_array($this->stock_availability, [ItemAvailability::PartiallyAvailable, ItemAvailability::Unavailable], true);
    }

    public function remainingProductionQuantity(): ?ItemMeasurement
    {
        if ($this->finished_stock_quantity === null) {
            return null;
        }

        $remaining = max(0, $this->measurement()->hundredths - ItemMeasurement::fromInput($this->finished_stock_quantity, $this->unit)->hundredths);
        $quantity = intdiv($remaining, 100).'.'.str_pad((string) ($remaining % 100), 2, '0', STR_PAD_LEFT);

        return ItemMeasurement::fromInput($quantity, $this->unit);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'quantity' => 'string',
            'unit_price' => 'decimal:2',
            'stock_availability' => ItemAvailability::class,
            'material_checklist' => 'array',
            'material_availability' => ItemAvailability::class,
            'materials_checked_at' => 'datetime',
            'materials_checked_by' => 'integer',
            'production_status' => ItemProductionStatus::class,
            'ready_check_required' => 'boolean',
            'availability_checked_at' => 'datetime',
            'ready_confirmed_at' => 'datetime',
            'availability_checked_by' => 'integer',
            'ready_confirmed_by' => 'integer',
        ];
    }
}
