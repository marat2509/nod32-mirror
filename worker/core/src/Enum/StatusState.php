<?php

declare(strict_types=1);

namespace Nod32Mirror\Enum;

enum StatusState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
