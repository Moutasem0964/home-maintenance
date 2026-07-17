<?php

namespace App\Enums;

enum NotificationCategory: string
{
    case Orders = 'orders';
    case Financial = 'financial';
    case Admin = 'admin';
}
