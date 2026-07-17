<?php

namespace App\Enums;

/**
 * Order lifecycle (data dictionary + addendum).
 * scheduled: booked with a confirmed appointment, waiting for activation.
 * inspection_only / no_show / expired / resolved close gaps found in the SRS review.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Scheduled = 'scheduled';
    case Accepted = 'accepted';
    case InProgress = 'in_progress';
    case WaitingForParts = 'waiting_for_parts';
    case Completed = 'completed';
    case Disputed = 'disputed';
    case Canceled = 'canceled';
    case InspectionOnly = 'inspection_only';
    case NoShow = 'no_show';
    case Expired = 'expired';
    case Resolved = 'resolved';
}
