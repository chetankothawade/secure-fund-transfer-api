<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Account;

interface AccountRepositoryInterface
{
    public function findForUpdate(string $accountId): Account;

    public function save(Account $account): void;
}
