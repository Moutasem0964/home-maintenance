<?php

namespace App\Enums;

enum DisputeResolution: string
{
    case FullRefund = 'full_refund';
    case PartialRefund = 'partial_refund';
    case ReleaseToTechnician = 'release_to_technician';
    case WarrantyOrder = 'warranty_order';
}
