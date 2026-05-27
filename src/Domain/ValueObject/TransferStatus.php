<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

enum TransferStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
}
