<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Command\TransferFundsCommand;
use App\Domain\Entity\Transaction;
use App\Domain\Event\TransferInitiated;
use App\Domain\Repository\AccountRepositoryInterface;
use App\Domain\Repository\TransactionRepositoryInterface;
use App\Domain\ValueObject\Money;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Predis\ClientInterface as RedisClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class TransferFundsHandler
{
    private const IDEMPOTENCY_TTL_SECONDS = 86400;
    private const MONEY_SCALE = 4;

    public function __construct(
        private AccountRepositoryInterface $accountRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private EntityManagerInterface $entityManager,
        private RedisClient $redis,
        private LoggerInterface $logger,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(TransferFundsCommand $command): ?string
    {
        return $this->handle($command);
    }

    public function handle(TransferFundsCommand $command): ?string
    {
        $redisKey = 'idempotency:'.$command->idempotencyKey;

        if ($command->idempotencyKey === '') {
            throw new InvalidArgumentException('Idempotency key is required.');
        }

        if ($command->fromAccountId === $command->toAccountId) {
            throw new InvalidArgumentException('Source and destination accounts must differ.');
        }

        $reserved = $this->redis->set($redisKey, 'processing', 'EX', self::IDEMPOTENCY_TTL_SECONDS, 'NX');

        if ((string) $reserved !== 'OK') {
            $this->logger->info('Duplicate transfer request skipped.', [
                'from_account_id' => $command->fromAccountId,
                'to_account_id' => $command->toAccountId,
                'idempotency_key' => $command->idempotencyKey,
            ]);

            return null;
        }

        try {
            $transaction = $this->entityManager->wrapInTransaction(function () use ($command): Transaction {
                $amount = Money::fromFloat($command->amount, $command->currency, self::MONEY_SCALE);
                $fromAccount = $this->accountRepository->findForUpdate($command->fromAccountId);
                $toAccount = $this->accountRepository->findForUpdate($command->toAccountId);

                $fromAccount->debit($amount);
                $toAccount->credit($amount);

                $this->accountRepository->save($fromAccount);
                $this->accountRepository->save($toAccount);

                $transaction = new Transaction(
                    id: $this->transactionRepository->nextIdentity(),
                    fromAccountId: $fromAccount->id,
                    toAccountId: $toAccount->id,
                    amount: $amount,
                    idempotencyKey: $command->idempotencyKey,
                );
                $transaction->complete();

                $this->transactionRepository->save($transaction);

                return $transaction;
            });
        } catch (\Throwable $exception) {
            $this->redis->del([$redisKey]);

            throw $exception;
        }

        $this->redis->set($redisKey, $transaction->id, 'EX', self::IDEMPOTENCY_TTL_SECONDS);
        $this->messageBus->dispatch(new TransferInitiated(
            transactionId: $transaction->id,
            fromAccountId: $transaction->fromAccountId,
            toAccountId: $transaction->toAccountId,
            amount: $transaction->amount,
        ));

        $this->logger->info('Transfer completed.', [
            'transaction_id' => $transaction->id,
            'from_account_id' => $transaction->fromAccountId,
            'to_account_id' => $transaction->toAccountId,
        ]);

        return $transaction->id;
    }
}
