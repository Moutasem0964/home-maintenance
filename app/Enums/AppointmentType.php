<?php

namespace App\Enums;

enum AppointmentType: string
{
    case Inspection = 'inspection';
    case Repair = 'repair';
    case Followup = 'followup';
}
