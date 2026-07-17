<?php

namespace App\Enums;

/** dispatch_offers = system→technician job offer. NOT the client-facing price quote. */
enum DispatchOfferStatus: string
{
    case Offered = 'offered';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
