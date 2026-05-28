<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Transaction;

interface TransactionRepositoryInterface
{
    public function nextIdentity(): string;

    public function save(Transaction $transaction): void;

    public function findIdByIdempotencyKey(string $idempotencyKey): ?string;
}
