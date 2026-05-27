<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repository\AccountRepositoryInterface;
use App\Domain\ValueObject\Money;
use Doctrine\DBAL\Connection;

final readonly class DoctrineAccountRepository implements AccountRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function transfer(string $fromAccountId, string $toAccountId, Money $amount, string $idempotencyKey): string
    {
        return $this->connection->transactional(function () use ($fromAccountId, $toAccountId, $amount, $idempotencyKey): string {
            $from = $this->connection->fetchAssociative(
                'SELECT * FROM accounts WHERE id = ? FOR UPDATE',
                [$fromAccountId]
            );
            $to = $this->connection->fetchAssociative(
                'SELECT * FROM accounts WHERE id = ? FOR UPDATE',
                [$toAccountId]
            );

            if ($from === false || $to === false) {
                throw new \RuntimeException('Account not found.');
            }

            if ($from['currency'] !== $amount->currency || $to['currency'] !== $amount->currency) {
                throw new \RuntimeException('Currency mismatch.');
            }

            $decimalAmount = number_format($amount->toFloat(), 4, '.', '');

            if (bccomp((string) $from['balance'], $decimalAmount, 4) < 0) {
                throw new \RuntimeException('Insufficient funds.');
            }

            $transactionId = $this->connection->fetchOne('SELECT uuid_generate_v4()');

            $this->connection->executeStatement(
                'UPDATE accounts SET balance = balance - ?, version = version + 1 WHERE id = ?',
                [$decimalAmount, $fromAccountId]
            );
            $this->connection->executeStatement(
                'UPDATE accounts SET balance = balance + ?, version = version + 1 WHERE id = ?',
                [$decimalAmount, $toAccountId]
            );
            $this->connection->insert('transactions', [
                'id' => $transactionId,
                'from_account_id' => $fromAccountId,
                'to_account_id' => $toAccountId,
                'amount' => $decimalAmount,
                'currency' => $amount->currency,
                'status' => 'completed',
                'idempotency_key' => $idempotencyKey,
            ]);

            return (string) $transactionId;
        });
    }
}
