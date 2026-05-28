<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Entity\Transaction;
use App\Domain\Repository\TransactionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final readonly class DoctrineTransactionRepository implements TransactionRepositoryInterface
{
    private const MONEY_SCALE = 4;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function nextIdentity(): string
    {
        $id = $this->entityManager->getConnection()->fetchOne('SELECT UUID()');

        if (! is_string($id) || $id === '') {
            throw new RuntimeException('Unable to generate transaction id.');
        }

        return $id;
    }

    public function save(Transaction $transaction): void
    {
        $this->entityManager->getConnection()->insert('transactions', [
            'id' => $transaction->id,
            'from_account_id' => $transaction->fromAccountId,
            'to_account_id' => $transaction->toAccountId,
            'amount' => number_format($transaction->amount->toFloat(self::MONEY_SCALE), self::MONEY_SCALE, '.', ''),
            'currency' => $transaction->amount->currency,
            'status' => $transaction->status()->value,
            'idempotency_key' => $transaction->idempotencyKey,
        ]);
    }
}
