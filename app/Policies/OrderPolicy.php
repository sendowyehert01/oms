<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class OrderPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Order $order): Response
    {
        return $order->created_by === $user->id || $order->customer?->created_by === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function recordPayment(User $user, Order $order): Response
    {
        return $this->view($user, $order);
    }

    public function setPaymentDueDate(User $user, Order $order): Response
    {
        return $this->view($user, $order)->allowed() ? Response::deny() : Response::denyAsNotFound();
    }

    public function updateStatus(User $user, Order $order): Response
    {
        return $this->view($user, $order)->allowed()
            ? Response::deny('Only administrators can update production progress.')
            : Response::denyAsNotFound();
    }

    public function delete(User $user, Order $order): Response
    {
        return $this->view($user, $order)->allowed() ? Response::deny() : Response::denyAsNotFound();
    }

    public function scheduleDelivery(User $user, Order $order): Response
    {
        return $order->created_by === $user->id
            ? Response::allow()
            : ($this->view($user, $order)->allowed() ? Response::deny() : Response::denyAsNotFound());
    }
}
