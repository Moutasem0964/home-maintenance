<?php

namespace App\Enums;

enum PromoAppliesTo: string
{
    case InspectionFee = 'inspection_fee';
    case Total = 'total';
}
