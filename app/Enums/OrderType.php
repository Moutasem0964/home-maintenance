<?php

namespace App\Enums;

enum OrderType: string
{
    case Urgent = 'urgent';
    case Scheduled = 'scheduled';
}
