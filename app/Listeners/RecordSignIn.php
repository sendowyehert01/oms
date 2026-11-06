<?php

namespace App\Listeners;

use App\AuditRecorder;
use App\Models\User;
use Illuminate\Auth\Events\Login;

class RecordSignIn
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Handle the event.
     */
    public function handle(Login $event): void
    {
        if ($event->guard === 'web' && $event->user instanceof User) {
            $this->audit->signedIn($event->user);
        }
    }
}
