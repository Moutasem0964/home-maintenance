<?php

namespace App\Enums;

enum UserRole: string
{
    case Client = 'client';
    case Technician = 'technician';
    case Admin = 'admin';
    case Support = 'support';
}
