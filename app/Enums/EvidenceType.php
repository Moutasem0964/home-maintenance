<?php

namespace App\Enums;

enum EvidenceType: string
{
    case Before = 'before';
    case After = 'after';
    case Invoice = 'invoice';
    case Dispute = 'dispute';
}
