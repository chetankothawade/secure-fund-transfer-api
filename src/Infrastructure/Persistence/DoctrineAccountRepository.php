<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Entity\Account;
use App\Domain\Exception\AccountNotFoundException;
use App\Domain\Repository\AccountRepositoryInterface;
use App\Domain\ValueObject\Money;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAccountRepository implements AccountRepositoryInterface
{
    private const MONEY_SCALE = 4;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findForUpdate(string $accountId): Account
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT * FROM accounts WHERE id = ? FOR UPDATE',
            [$accountId]
        );

        if ($row === false) {
            throw AccountNotFoundException::forAccount($accountId);
        }

        return new Account(
            id: (string) $row['id'],
            ownerName: (string) $row['owner_name'],
            balance: Money::fromDecimal((string) $row['balance'], (string) $row['currency'], self::MONEY_SCALE),
            version: (int) $row['version'],
        );
    }

    public function save(Account $account): void
    {
        $this->entityManager->getConnection()->update('accounts', [
            'balance' => $account->balance()->toDecimal(self::MONEY_SCALE),
            'version' => $account->version,
        ], [
            'id' => $account->id,
        ]);
    }
}
