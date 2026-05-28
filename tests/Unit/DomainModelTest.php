<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Entity\Account;
use App\Domain\Entity\Transaction;
use App\Domain\Exception\InsufficientFunds;
use App\Domain\Exception\InvalidTransactionState;
use App\Domain\ValueObject\Money;
use App\Domain\ValueObject\TransferStatus;
use PHPUnit\Framework\TestCase;

final class DomainModelTest extends TestCase
{
    public function testMoneyAddsAndSubtractsMinorUnits(): void
    {
        $money = new Money(10_00, 'usd');

        self::assertSame(15_50, $money->add(new Money(5_50, 'USD'))->minorUnits);
        self::assertSame(7_50, $money->subtract(new Money(2_50, 'USD'))->minorUnits);
        self::assertTrue($money->isGreaterThan(new Money(9_99, 'USD')));
        self::assertSame(1234, Money::fromFloat(12.34, 'USD')->minorUnits);
    }

    public function testAccountRejectsInsufficientFunds(): void
    {
        $account = new Account('account-1', 'Ada Lovelace', new Money(100, 'USD'));

        $this->expectException(InsufficientFunds::class);

        $account->debit(new Money(101, 'USD'));
    }

    public function testTransactionCanOnlyLeavePendingOnce(): void
    {
        $transaction = new Transaction(
            id: 'transaction-1',
            fromAccountId: 'account-1',
            toAccountId: 'account-2',
            amount: new Money(100, 'USD'),
        );

        $transaction->complete();

        self::assertSame(TransferStatus::Completed, $transaction->status());

        $this->expectException(InvalidTransactionState::class);

        $transaction->fail();
    }
}
