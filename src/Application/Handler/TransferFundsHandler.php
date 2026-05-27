<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Command\TransferFundsCommand;
use App\Domain\Repository\AccountRepositoryInterface;
use App\Domain\ValueObject\Money;
use App\Infrastructure\Audit\AuditLoggerInterface;
use App\Infrastructure\Redis\IdempotencyStoreInterface;

final readonly class TransferFundsHandler
{
    public function __construct(
        private AccountRepositoryInterface $accounts,
        private IdempotencyStoreInterface $idempotency,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public function handle(TransferFundsCommand $command): string
    {
        $this->idempotency->reserve($command->idempotencyKey, ttlSeconds: 86400);

        $money = Money::fromFloat((float) $command->amount, $command->currency);
        $transactionId = $this->accounts->transfer(
            $command->fromAccountId,
            $command->toAccountId,
            $money,
            $command->idempotencyKey,
        );

        $this->auditLogger->record($transactionId, 'transfer.accepted', [
            'from_account_id' => $command->fromAccountId,
            'to_account_id' => $command->toAccountId,
            'amount_minor_units' => $money->minorUnits,
            'currency' => $money->currency,
        ]);

        return $transactionId;
    }
}
