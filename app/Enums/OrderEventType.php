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
    case ClosureAutoCompleted = 'closure_auto_completed';
    case Completed = 'completed';
    case Disputed = 'disputed';
    case DisputeResolved = 'dispute_resolved';
    case FundsHeld = 'funds_held';
    case FundsReleased = 'funds_released';
    case Refunded = 'refunded';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case NoShowReported = 'no_show_reported'; // client reported a suspected tech no-show (awaiting admin)
    case ClientNoShowReported = 'client_no_show_reported'; // tech reported a suspected client no-show (awaiting admin)
    case NoShow = 'no_show';
    case TechnicianWithdrew = 'technician_withdrew'; // decline-after-accept → re-dispatch
    case WarrantyClaimed = 'warranty_claimed';       // client files a warranty visit → child order
    case WarrantyReassigned = 'warranty_reassigned'; // warranty visit sent to the pool for a paid substitute
    case SubstitutePaid = 'substitute_paid';         // platform paid the substitute the original labor cost
    case SubstitutePayoutPending = 'substitute_payout_pending'; // platform wallet short — payout awaits top-up
}
