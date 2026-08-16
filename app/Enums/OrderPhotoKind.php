<?php

namespace App\Enums;

/** What an order photo documents: the client's reported flaw, or the tech's completion evidence. */
enum OrderPhotoKind: string
{
    case Flaw = 'flaw';
    case Closure = 'closure';
    case Dispute = 'dispute'; // evidence attached by the client when raising a dispute
}
