<?php

namespace App\Enums;

enum OrderKind: string
{
    case Normal = 'normal';
    case Warranty = 'warranty'; // zero-cost follow-up visit; original tech first, else a paid substitute
    case Addon = 'addon';       // born from an addon quote
}
