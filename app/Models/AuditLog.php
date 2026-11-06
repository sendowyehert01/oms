<?php

namespace App\Models;

use App\AuditAction;
use App\UserRole;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['actor_id', 'actor_name', 'actor_role', 'action', 'subject_type', 'subject_id', 'subject_name', 'order_id', 'order_number', 'statement'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $log): never {
            throw new LogicException('Audit logs cannot be edited.');
        });
        static::deleting(function (self $log): never {
            throw new LogicException('Audit logs cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'actor_role' => UserRole::class,
            'action' => AuditAction::class,
            'subject_id' => 'integer',
            'order_id' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
