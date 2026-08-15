<?php

namespace App\Enums;

enum TechnicianFlagReason: string
{
    case NoShow = 'no_show';        // client reported the tech never arrived
    case ClientNoShow = 'client_no_show'; // tech reported the client was absent on-site (a claim to verify, NOT a tech offense)
    case Withdrawal = 'withdrawal'; // tech dropped a job they had accepted
    case PartsDelay = 'parts_delay'; // waiting-for-parts exceeded the max window

    /**
     * Reasons that count as technician reliability offenses (feed the flags queue, the
     * open-count signal, and the suspend/ban sweep). Excludes claims like ClientNoShow,
     * which are resolved only through the order no-show endpoint.
     *
     * @return array<int, string>
     */
    public static function technicianOffenseValues(): array
    {
        return [self::NoShow->value, self::Withdrawal->value, self::PartsDelay->value];
    }
}
