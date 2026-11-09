<?php

namespace App;

enum UserRole: string
{
    case Admin = 'admin';
    case Staff = 'staff';
    case ProductionMonitor = 'production_monitor';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Staff => 'Staff',
            self::ProductionMonitor => 'Production Monitor',
        };
    }
}
