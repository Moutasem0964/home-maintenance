<?php

namespace App\Enums;

enum TechnicianFlagReason: string
{
    case NoShow = 'no_show';        // client reported the tech never arrived
    case Withdrawal = 'withdrawal'; // tech dropped a job they had accepted
    case PartsDelay = 'parts_delay'; // waiting-for-parts exceeded the max window
}
