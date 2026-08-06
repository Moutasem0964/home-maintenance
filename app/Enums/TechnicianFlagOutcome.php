<?php

namespace App\Enums;

/** The admin's decision on a reliability flag (set when it moves from open to reviewed). */
enum TechnicianFlagOutcome: string
{
    case Dismissed = 'dismissed';  // no action — one-off / excusable / unverified; tech keeps standing
    case Suspended = 'suspended';  // recoverable penalty — tech moved to probation and taken offline
    case Banned = 'banned';        // terminal — tech removed and taken offline
}
