<?php

namespace App\Enums;

enum QuoteType: string
{
    case Initial = 'initial';
    case Addon = 'addon'; // extra fault discovered mid-job
}
