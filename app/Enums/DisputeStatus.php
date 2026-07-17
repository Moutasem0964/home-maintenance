<?php

namespace App\Enums;

enum DisputeStatus: string
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Escalated = 'escalated'; // to engineering consultant
    case Resolved = 'resolved';
}
