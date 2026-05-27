<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use App\Domain\ValueObject\TransferStatus;

final class InvalidTransactionState extends DomainException
{
    public static function cannotTransition(TransferStatus $current, TransferStatus $next): self
    {
        return new self(sprintf('Cannot transition transaction from "%s" to "%s".', $current->value, $next->value));
    }
}
