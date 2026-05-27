<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Exception\InsufficientFunds;
use App\Domain\ValueObject\Money;

final class Account
{
    public function __construct(
        public readonly string $id,
        public readonly string $ownerName,
        private Money $balance,
        public int $version = 1,
    ) {
    }

    public function debit(Money $amount): void
    {
        if ($amount->isGreaterThan($this->balance)) {
            throw InsufficientFunds::forAccount($this->id);
        }

        $this->balance = $this->balance->subtract($amount);
        ++$this->version;
    }

    public function credit(Money $amount): void
    {
        $this->balance = $this->balance->add($amount);
        ++$this->version;
    }

    public function balance(): Money
    {
        return $this->balance;
    }
}
