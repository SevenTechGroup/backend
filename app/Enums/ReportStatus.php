<?php

namespace App\Enums;

enum ReportStatus: string
{
    case Received = 'received';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
}
