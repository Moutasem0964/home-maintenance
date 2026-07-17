<?php

namespace App\Enums;

enum TicketType: string
{
    case AppIssue = 'app_issue';
    case Financial = 'financial';
    case Other = 'other';
}
