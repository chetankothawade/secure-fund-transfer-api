<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class AccountNotFoundException extends DomainException
{
    public static function forAccount(string $accountId): self
    {
        return new self(sprintf('Account "%s" was not found.', $accountId));
    }
}
