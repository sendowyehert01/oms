<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_name', 'contact_person', 'address', 'contact_number'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @param Builder<Customer> $query */
    #[Scope]
    protected function createdBy(Builder $query, User $user): void
    {
        $query->where('customers.created_by', $user->id);
    }

    /** @param Builder<Customer> $query */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if (! $user->isAdmin()) {
            $query->createdBy($user);
        }
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_by' => 'integer'];
    }
}
