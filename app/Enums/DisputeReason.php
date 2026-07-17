<?php

namespace App\Enums;

enum DisputeReason: string
{
    case FaultReturned = 'fault_returned';
    case HomeDamage = 'home_damage';
    case DifferentPart = 'different_part';
    case Other = 'other';
}
