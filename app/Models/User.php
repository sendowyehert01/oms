<?php

namespace App\Models;

use App\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['password' => 'hashed', 'email_verified_at' => 'datetime', 'role' => UserRole::class];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isProductionMonitor(): bool
    {
        return $this->role === UserRole::ProductionMonitor;
    }

    /** @return HasMany<Customer, $this> */
    public function createdClients(): HasMany
    {
        return $this->hasMany(Customer::class, 'created_by');
    }

    /** @return HasMany<Order, $this> */
    public function createdOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'created_by');
    }
}
