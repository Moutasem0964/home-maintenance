<?php

namespace App\Enums;

enum TopUpStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Rejected = 'rejected'; // admin declined a manual (receipt-backed) top-up
}
