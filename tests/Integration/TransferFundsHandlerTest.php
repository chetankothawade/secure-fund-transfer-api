<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Command\TransferFundsCommand;
use App\Application\Handler\TransferFundsHandler;
use App\Domain\Entity\Account;
use App\Domain\Entity\Transaction;
use App\Domain\Event\TransferInitiated;
use App\Domain\Repository\AccountRepositoryInterface;
use App\Domain\Repository\TransactionRepositoryInterface;
use App\Domain\ValueObject\Money;
use App\Infrastructure\Redis\IdempotencyService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Predis\Command\CommandInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class TransferFundsHandlerTest extends TestCase
{
    public function testTransfersFundsPersistsTransactionAndDispatchesEvent(): void
    {
        $accounts = new InMemoryAccountRepository([
            'from' => new Account('from', 'From Account', new Money(10_0000, 'USD')),
            'to' => new Account('to', 'To Account', new Money(1_0000, 'USD')),
        ]);
        $transactions = new InMemoryTransactionRepository();
        $redis = new InMemoryRedisClient();
        $messageBus = new RecordingMessageBus();

        $handler = new TransferFundsHandler(
            $accounts,
            $transactions,
            $this->transactionalEntityManager(expectsTransaction: true),
            new IdempotencyService($redis),
            new NullLogger(),
            $messageBus,
        );

        $transactionId = $handler(new TransferFundsCommand('from', 'to', '2.50', 'USD', 'request-1'));

        self::assertSame('transaction-1', $transactionId);
        self::assertSame(7_5000, $accounts->accounts['from']->balance()->minorUnits);
        self::assertSame(3_5000, $accounts->accounts['to']->balance()->minorUnits);
        self::assertSame('completed', $transactions->transactions[0]->status()->value);
        self::assertStringContainsString('transaction-1', $redis->values['idempotency:request-1']);
        self::assertInstanceOf(TransferInitiated::class, $messageBus->messages[0]);
    }

    public function testDuplicateIdempotencyKeyReturnsEarly(): void
    {
        $accounts = new InMemoryAccountRepository([
            'from' => new Account('from', 'From Account', new Money(10_0000, 'USD')),
            'to' => new Account('to', 'To Account', new Money(1_0000, 'USD')),
        ]);
        $fingerprint = hash('sha256', json_encode([
            'from_account_id' => 'from',
            'to_account_id' => 'to',
            'amount' => '2.5000',
            'currency' => 'USD',
        ], JSON_THROW_ON_ERROR));
        $redis = new InMemoryRedisClient([
            'idempotency:request-1' => json_encode([
                'status' => 'completed',
                'fingerprint' => $fingerprint,
                'transaction_id' => 'transaction-1',
            ], JSON_THROW_ON_ERROR),
        ]);

        $handler = new TransferFundsHandler(
            $accounts,
            new InMemoryTransactionRepository(),
            $this->transactionalEntityManager(expectsTransaction: false),
            new IdempotencyService($redis),
            new NullLogger(),
            new RecordingMessageBus(),
        );

        self::assertSame('transaction-1', $handler(new TransferFundsCommand('from', 'to', '2.50', 'USD', 'request-1')));
        self::assertSame(0, $accounts->locks);
    }

    public function testRejectsIdempotencyKeyReusedForDifferentPayload(): void
    {
        $redis = new InMemoryRedisClient([
            'idempotency:request-1' => json_encode([
                'status' => 'completed',
                'fingerprint' => 'different-fingerprint',
                'transaction_id' => 'transaction-1',
            ], JSON_THROW_ON_ERROR),
        ]);

        $handler = new TransferFundsHandler(
            new InMemoryAccountRepository([]),
            new InMemoryTransactionRepository(),
            $this->transactionalEntityManager(expectsTransaction: false),
            new IdempotencyService($redis),
            new NullLogger(),
            new RecordingMessageBus(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('different transfer request');

        $handler(new TransferFundsCommand('from', 'to', '2.50', 'USD', 'request-1'));
    }

    public function testRecoversCommittedTransactionWhenRedisResultIsMissing(): void
    {
        $transactions = new InMemoryTransactionRepository();
        $transactions->existingByIdempotencyKey['request-1'] = 'transaction-99';
        $redis = new InMemoryRedisClient();

        $handler = new TransferFundsHandler(
            new InMemoryAccountRepository([]),
            $transactions,
            $this->transactionalEntityManager(expectsTransaction: false),
            new IdempotencyService($redis),
            new NullLogger(),
            new RecordingMessageBus(),
        );

        self::assertSame('transaction-99', $handler(new TransferFundsCommand('from', 'to', '2.50', 'USD', 'request-1')));
        self::assertStringContainsString('transaction-99', $redis->values['idempotency:request-1']);
    }

    public function testRecoversCommittedTransactionWhenRedisStillShowsProcessing(): void
    {
        $fingerprint = hash('sha256', json_encode([
            'from_account_id' => 'from',
            'to_account_id' => 'to',
            'amount' => '2.5000',
            'currency' => 'USD',
        ], JSON_THROW_ON_ERROR));
        $transactions = new InMemoryTransactionRepository();
        $transactions->existingByIdempotencyKey['request-1'] = 'transaction-99';
        $redis = new InMemoryRedisClient([
            'idempotency:request-1' => json_encode([
                'status' => 'processing',
                'fingerprint' => $fingerprint,
            ], JSON_THROW_ON_ERROR),
        ]);

        $handler = new TransferFundsHandler(
            new InMemoryAccountRepository([]),
            $transactions,
            $this->transactionalEntityManager(expectsTransaction: false),
            new IdempotencyService($redis),
            new NullLogger(),
            new RecordingMessageBus(),
        );

        self::assertSame('transaction-99', $handler(new TransferFundsCommand('from', 'to', '2.50', 'USD', 'request-1')));
        self::assertStringContainsString('transaction-99', $redis->values['idempotency:request-1']);
    }

    public function testLocksAccountsInStableOrderToReduceDeadlocks(): void
    {
        $accounts = new InMemoryAccountRepository([
            'z-account' => new Account('z-account', 'From Account', new Money(10_0000, 'USD')),
            'a-account' => new Account('a-account', 'To Account', new Money(1_0000, 'USD')),
        ]);

        $handler = new TransferFundsHandler(
            $accounts,
            new InMemoryTransactionRepository(),
            $this->transactionalEntityManager(expectsTransaction: true),
            new IdempotencyService(new InMemoryRedisClient()),
            new NullLogger(),
            new RecordingMessageBus(),
        );

        $handler(new TransferFundsCommand('z-account', 'a-account', '2.50', 'USD', 'request-1'));

        self::assertSame(['a-account', 'z-account'], $accounts->lockOrder);
    }

    private function transactionalEntityManager(bool $expectsTransaction): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects($expectsTransaction ? self::once() : self::never())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());

        return $entityManager;
    }
}

final class InMemoryAccountRepository implements AccountRepositoryInterface
{
    public int $locks = 0;

    /** @var list<string> */
    public array $lockOrder = [];

    /**
     * @param array<string, Account> $accounts
     */
    public function __construct(public array $accounts)
    {
    }

    public function findForUpdate(string $accountId): Account
    {
        ++$this->locks;
        $this->lockOrder[] = $accountId;

        return $this->accounts[$accountId];
    }

    public function save(Account $account): void
    {
        $this->accounts[$account->id] = $account;
    }
}

final class InMemoryTransactionRepository implements TransactionRepositoryInterface
{
    /** @var list<Transaction> */
    public array $transactions = [];

    /** @var array<string, string> */
    public array $existingByIdempotencyKey = [];

    public function nextIdentity(): string
    {
        return 'transaction-'.(\count($this->transactions) + 1);
    }

    public function save(Transaction $transaction): void
    {
        $this->transactions[] = $transaction;
        $this->existingByIdempotencyKey[(string) $transaction->idempotencyKey] = $transaction->id;
    }

    public function findIdByIdempotencyKey(string $idempotencyKey): ?string
    {
        return $this->existingByIdempotencyKey[$idempotencyKey] ?? null;
    }
}

final class RecordingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return new Envelope($message, $stamps);
    }
}

final class InMemoryRedisClient implements ClientInterface
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(public array $values = [])
    {
    }

    public function getCommandFactory()
    {
        throw new \BadMethodCallException();
    }

    public function getOptions()
    {
        throw new \BadMethodCallException();
    }

    public function connect()
    {
    }

    public function disconnect()
    {
    }

    public function getConnection()
    {
        throw new \BadMethodCallException();
    }

    public function createCommand($method, $arguments = [])
    {
        throw new \BadMethodCallException();
    }

    public function executeCommand(CommandInterface $command)
    {
        throw new \BadMethodCallException();
    }

    public function __call($method, $arguments): mixed
    {
        if ($method === 'set') {
            $key = $arguments[0];
            $value = $arguments[1];
            $flag = $arguments[4] ?? null;

            if ($flag === 'NX' && isset($this->values[$key])) {
                return null;
            }

            $this->values[$key] = (string) $value;

            return 'OK';
        }

        if ($method === 'del') {
            foreach ((array) $arguments[0] as $key) {
                unset($this->values[$key]);
            }

            return 1;
        }

        if ($method === 'get') {
            return $this->values[$arguments[0]] ?? null;
        }

        throw new \BadMethodCallException($method);
    }
}
