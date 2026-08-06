<?php

namespace App\Enums;

enum TechnicianFlagStatus: string
{
    case Open = 'open';         // awaiting admin assessment
    case Reviewed = 'reviewed'; // admin has assessed it (dismissed or acted on)
}
