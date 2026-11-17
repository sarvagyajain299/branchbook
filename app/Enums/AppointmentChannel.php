<?php

declare(strict_types=1);

namespace App\Enums;

enum AppointmentChannel: string
{
    case Online = 'online';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Staff => 'Booked by staff',
        };
    }
}
