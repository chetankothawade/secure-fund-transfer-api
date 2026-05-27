<?php

declare(strict_types=1);

namespace App\Domain\Event;

use App\Domain\ValueObject\Money;

final readonly class TransferInitiated
{
    public function __construct(
        public string $transactionId,
        public string $fromAccountId,
        public string $toAccountId,
        public Money $amount,
        public \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {
    }
}
