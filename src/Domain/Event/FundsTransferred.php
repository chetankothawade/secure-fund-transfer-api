<?php

declare(strict_types=1);

namespace App\Domain\Event;

final readonly class FundsTransferred
{
    public function __construct(
        public string $transactionId,
        public string $fromAccountId,
        public string $toAccountId,
    ) {
    }
}
