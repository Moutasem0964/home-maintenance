<?php

namespace App\Enums;

/** Booking lifecycle only. Arrival/completion/no-show are ORDER states — single source of truth. */
enum AppointmentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Activated = 'activated';
    case Canceled = 'canceled';
}
