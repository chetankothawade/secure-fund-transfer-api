<?php

declare(strict_types=1);

namespace App\Application\Command;

final readonly class TransferFundsCommand
{
    public function __construct(
        public string $fromAccountId,
        public string $toAccountId,
        public string $amount,
        public string $currency,
        public string $idempotencyKey,
    ) {
    }
}
