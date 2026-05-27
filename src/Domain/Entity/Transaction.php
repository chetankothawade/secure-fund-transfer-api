<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Exception\InvalidTransactionState;
use App\Domain\ValueObject\Money;
use App\Domain\ValueObject\TransferStatus;

final class Transaction
{
    public function __construct(
        public readonly string $id,
        public readonly string $fromAccountId,
        public readonly string $toAccountId,
        public readonly Money $amount,
        private TransferStatus $status = TransferStatus::Pending,
        public readonly ?string $idempotencyKey = null,
    ) {
    }

    public function status(): TransferStatus
    {
        return $this->status;
    }

    public function complete(): void
    {
        $this->transitionTo(TransferStatus::Completed);
    }

    public function fail(): void
    {
        $this->transitionTo(TransferStatus::Failed);
    }

    private function transitionTo(TransferStatus $nextStatus): void
    {
        if ($this->status !== TransferStatus::Pending) {
            throw InvalidTransactionState::cannotTransition($this->status, $nextStatus);
        }

        $this->status = $nextStatus;
    }
}
