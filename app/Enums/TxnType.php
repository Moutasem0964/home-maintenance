<?php

namespace App\Enums;

/** Ledger entry types — entries are written in pairs summing to zero. */
enum TxnType: string
{
    case Deposit = 'deposit';
    case Hold = 'hold';
    case Release = 'release';
    case Refund = 'refund';
    case Commission = 'commission';
    case Payout = 'payout';
    case Withdrawal = 'withdrawal';
    case Reversal = 'reversal'; // corrections are reversal entries, never edits
}
