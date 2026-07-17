<?php

namespace App\Enums;

enum WithdrawalStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case Rejected = 'rejected';
}
