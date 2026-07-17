<?php

namespace App\Enums;

/** Append-only audit trail vocabulary — extend freely, never rename shipped cases. */
enum OrderEventType: string
{
    case Created = 'created';
    case Dispatched = 'dispatched';
    case OfferAccepted = 'offer_accepted';
    case OfferRejected = 'offer_rejected';
    case OfferExpired = 'offer_expired';
    case AppointmentBooked = 'appointment_booked';
    case AppointmentConfirmed = 'appointment_confirmed';
    case AppointmentActivated = 'appointment_activated';
    case Arrived = 'arrived';
    case QuoteSent = 'quote_sent';
    case QuoteApproved = 'quote_approved';
    case QuoteRejected = 'quote_rejected';
    case QuoteExpired = 'quote_expired';
    case WorkStarted = 'work_started';
    case WaitingForParts = 'waiting_for_parts';
    case ClosureGenerated = 'closure_generated';
    case ClosureVerified = 'closure_verified';
    case Completed = 'completed';
    case Disputed = 'disputed';
    case DisputeResolved = 'dispute_resolved';
    case FundsHeld = 'funds_held';
    case FundsReleased = 'funds_released';
    case Refunded = 'refunded';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case NoShow = 'no_show';
    case TechnicianWithdrew = 'technician_withdrew'; // decline-after-accept → re-dispatch
}
