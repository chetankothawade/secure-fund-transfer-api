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
            $redis,
            new NullLogger(),
            $messageBus,
        );

        $transactionId = $handler(new TransferFundsCommand('from', 'to', 2.50, 'USD', 'request-1'));

        self::assertSame('transaction-1', $transactionId);
        self::assertSame(7_5000, $accounts->accounts['from']->balance()->minorUnits);
        self::assertSame(3_5000, $accounts->accounts['to']->balance()->minorUnits);
        self::assertSame('completed', $transactions->transactions[0]->status()->value);
        self::assertSame('transaction-1', $redis->values['idempotency:request-1']);
        self::assertInstanceOf(TransferInitiated::class, $messageBus->messages[0]);
    }

    public function testDuplicateIdempotencyKeyReturnsEarly(): void
    {
        $accounts = new InMemoryAccountRepository([
            'from' => new Account('from', 'From Account', new Money(10_0000, 'USD')),
            'to' => new Account('to', 'To Account', new Money(1_0000, 'USD')),
        ]);
        $redis = new InMemoryRedisClient([
            'idempotency:request-1' => 'transaction-1',
        ]);

        $handler = new TransferFundsHandler(
            $accounts,
            new InMemoryTransactionRepository(),
            $this->transactionalEntityManager(expectsTransaction: false),
            $redis,
            new NullLogger(),
            new RecordingMessageBus(),
        );

        self::assertNull($handler(new TransferFundsCommand('from', 'to', 2.50, 'USD', 'request-1')));
        self::assertSame(0, $accounts->locks);
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

    /**
     * @param array<string, Account> $accounts
     */
    public function __construct(public array $accounts)
    {
    }

    public function findForUpdate(string $accountId): Account
    {
        ++$this->locks;

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

    public function nextIdentity(): string
    {
        return 'transaction-'.(\count($this->transactions) + 1);
    }

    public function save(Transaction $transaction): void
    {
        $this->transactions[] = $transaction;
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

        throw new \BadMethodCallException($method);
    }
}
