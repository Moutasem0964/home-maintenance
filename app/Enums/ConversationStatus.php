<?php

namespace App\Enums;

enum ConversationStatus: string
{
    case Open = 'open';
    case ReadOnly = 'read_only'; // locked at order closure
    case Closed = 'closed';
}
