<?php

namespace App\Enums;

enum SettingDataType: string
{
    case String = 'string';
    case Int = 'int';
    case Decimal = 'decimal';
    case Bool = 'bool';
}
