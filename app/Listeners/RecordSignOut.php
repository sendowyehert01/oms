<?php

namespace App\Listeners;

use App\AuditRecorder;
use App\Models\User;
use Illuminate\Auth\Events\Logout;

class RecordSignOut
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Handle the event.
     */
    public function handle(Logout $event): void
    {
        if ($event->guard === 'web' && $event->user instanceof User) {
            $this->audit->signedOut($event->user);
        }
    }
}
