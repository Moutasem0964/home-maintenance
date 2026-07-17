<?php

namespace App\Enums;

/** Lifecycle: pending → probation → active; banned from anywhere. Rejection returns to pending (not a state). */
enum TechnicianStatus: string
{
    case Pending = 'pending';
    case Probation = 'probation';
    case Active = 'active';
    case Banned = 'banned';
}
