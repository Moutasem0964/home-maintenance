<?php

namespace App\Enums;

enum OrderKind: string
{
    case Normal = 'normal';
    case Warranty = 'warranty'; // same technician, zero labor cost
    case Addon = 'addon';       // born from an addon quote
}
