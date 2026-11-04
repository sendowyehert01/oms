<?php

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['customer_id', 'order_date', 'due_date', 'terms_of_payment', 'status', 'submission_key'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderByDesc('id');
    }

    /** @param Builder<Order> $query */
    #[Scope]
    protected function createdBy(Builder $query, User $user): void
    {
        $query->where('orders.created_by', $user->id);
    }

    /** @param Builder<Order> $query */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if (! $user->isAdmin()) {
            $query->where(fn (Builder $query): Builder => $query
                ->createdBy($user)
                ->orWhereHas('customer', fn (Builder $query): Builder => $query->createdBy($user)));
        }
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'created_by' => 'integer',
            'workflow_version' => 'integer',
            'item_tracking_started_at' => 'datetime',
            'delivery_date' => 'date:Y-m-d',
            'delivery_confirmed_by' => 'integer',
            'delivery_confirmed_at' => 'datetime',
            'order_date' => 'date:Y-m-d',
            'payment_due_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'target_delivery_date' => 'date:Y-m-d',
            'stage_started_at' => 'datetime',
        ];
    }
}
