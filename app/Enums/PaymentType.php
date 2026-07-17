<?php

namespace App\Enums;

enum PaymentType: string
{
    case Inspection = 'inspection';
    case Repair = 'repair';
    case Addon = 'addon';
}
