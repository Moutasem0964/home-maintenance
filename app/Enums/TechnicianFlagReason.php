<?php

namespace App\Enums;

enum TechnicianFlagReason: string
{
    case NoShow = 'no_show';        // client reported the tech never arrived
    case Withdrawal = 'withdrawal'; // tech dropped a job they had accepted
}
