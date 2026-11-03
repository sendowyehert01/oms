<?php

namespace App\Models;

use App\ItemMeasurement;
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'quantity' => 'string',
            'unit_price' => 'decimal:2',
        ];
    }
}
