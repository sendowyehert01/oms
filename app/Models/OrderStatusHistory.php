<?php

namespace App\Models;

use Database\Factories\OrderStatusHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['order_id', 'order_item_id', 'item_name', 'actor_id', 'actor_name', 'event_type', 'from_status', 'to_status', 'notes', 'submission_key', 'request_hash'])]
class OrderStatusHistory extends Model
{
    public const UPDATED_AT = null;

    /** @use HasFactory<OrderStatusHistoryFactory> */
    use HasFactory;

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $history): never {
            throw new LogicException('Workflow history cannot be edited. Record a correction instead.');
        });
        static::deleting(function (self $history): never {
            throw new LogicException('Workflow history cannot be deleted independently of its order.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['order_id' => 'integer', 'order_item_id' => 'integer', 'actor_id' => 'integer', 'created_at' => 'datetime'];
    }
}
