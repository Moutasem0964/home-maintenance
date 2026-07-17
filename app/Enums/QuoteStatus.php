<?php

namespace App\Enums;

enum QuoteStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected'; // order closes as inspection_only
    case Expired = 'expired';
}
