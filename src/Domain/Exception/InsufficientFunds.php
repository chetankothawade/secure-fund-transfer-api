<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class InsufficientFunds extends DomainException
{
    public static function forAccount(string $accountId): self
    {
        return new self(sprintf('Account "%s" has insufficient funds.', $accountId));
    }
}
