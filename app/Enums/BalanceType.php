<?php

namespace App\Enums;

enum BalanceType: string
{
    case Available = 'available';
    case Held = 'held';
}
