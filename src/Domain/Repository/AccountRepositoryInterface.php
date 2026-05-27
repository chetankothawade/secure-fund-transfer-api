<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\ValueObject\Money;

interface AccountRepositoryInterface
{
    public function transfer(string $fromAccountId, string $toAccountId, Money $amount, string $idempotencyKey): string;
}
