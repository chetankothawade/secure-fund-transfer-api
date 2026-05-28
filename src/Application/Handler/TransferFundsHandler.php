<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Command\TransferFundsCommand;
use App\Domain\Entity\Transaction;
use App\Domain\Event\TransferInitiated;
use App\Domain\Repository\AccountRepositoryInterface;
use App\Domain\Repository\TransactionRepositoryInterface;
use App\Domain\ValueObject\Money;
use App\Infrastructure\Redis\IdempotencyStoreInterface;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class TransferFundsHandler
{
    private const MONEY_SCALE = 4;
    private const MAX_TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private AccountRepositoryInterface $accountRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private EntityManagerInterface $entityManager,
        private IdempotencyStoreInterface $idempotency,
        private LoggerInterface $logger,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(TransferFundsCommand $command): string
    {
        return $this->handle($command);
    }

    public function handle(TransferFundsCommand $command): string
    {
        if ($command->idempotencyKey === '') {
            throw new InvalidArgumentException('Idempotency key is required.');
        }

        if ($command->fromAccountId === $command->toAccountId) {
            throw new InvalidArgumentException('Source and destination accounts must differ.');
        }

        $amount = Money::fromDecimal($command->amount, $command->currency, self::MONEY_SCALE);
        $fingerprint = $this->fingerprint($command, $amount);
        $idempotencyResult = $this->idempotency->start($command->idempotencyKey, $fingerprint);

        if ($idempotencyResult->fingerprintMismatch) {
            throw new InvalidArgumentException('Idempotency key was already used for a different transfer request.');
        }

        if ($idempotencyResult->transactionId !== null) {
            $this->logger->info('Duplicate transfer request skipped.', [
                'transaction_id' => $idempotencyResult->transactionId,
                'from_account_id' => $command->fromAccountId,
                'to_account_id' => $command->toAccountId,
                'idempotency_key' => $command->idempotencyKey,
            ]);

            return $idempotencyResult->transactionId;
        }

        if ($idempotencyResult->processing) {
            $existingTransactionId = $this->transactionRepository->findIdByIdempotencyKey($command->idempotencyKey);

            if ($existingTransactionId !== null) {
                $this->completeIdempotency($command->idempotencyKey, $fingerprint, $existingTransactionId);

                return $existingTransactionId;
            }

            throw new InvalidArgumentException('A transfer with this idempotency key is still processing.');
        }

        $existingTransactionId = $this->transactionRepository->findIdByIdempotencyKey($command->idempotencyKey);

        if ($existingTransactionId !== null) {
            $this->completeIdempotency($command->idempotencyKey, $fingerprint, $existingTransactionId);

            return $existingTransactionId;
        }

        try {
            $transaction = $this->runInRetryableTransaction(function () use ($command, $amount): Transaction {
                $accounts = $this->lockAccounts($command->fromAccountId, $command->toAccountId);
                $fromAccount = $accounts[$command->fromAccountId];
                $toAccount = $accounts[$command->toAccountId];

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
        } catch (UniqueConstraintViolationException $exception) {
            $existingTransactionId = $this->transactionRepository->findIdByIdempotencyKey($command->idempotencyKey);

            if ($existingTransactionId !== null) {
                $this->completeIdempotency($command->idempotencyKey, $fingerprint, $existingTransactionId);

                return $existingTransactionId;
            }

            $this->idempotency->release($command->idempotencyKey);

            throw $exception;
        } catch (\Throwable $exception) {
            $this->idempotency->release($command->idempotencyKey);

            throw $exception;
        }

        $this->completeIdempotency($command->idempotencyKey, $fingerprint, $transaction->id);
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

    /**
     * @return array<string, \App\Domain\Entity\Account>
     */
    private function lockAccounts(string $fromAccountId, string $toAccountId): array
    {
        $accountIds = [$fromAccountId, $toAccountId];
        sort($accountIds, SORT_STRING);

        $accounts = [];

        foreach ($accountIds as $accountId) {
            $accounts[$accountId] = $this->accountRepository->findForUpdate($accountId);
        }

        return $accounts;
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function runInRetryableTransaction(callable $operation): mixed
    {
        for ($attempt = 1; $attempt <= self::MAX_TRANSACTION_ATTEMPTS; ++$attempt) {
            try {
                return $this->entityManager->wrapInTransaction($operation);
            } catch (RetryableException $exception) {
                if ($attempt === self::MAX_TRANSACTION_ATTEMPTS) {
                    throw $exception;
                }

                usleep(50_000 * $attempt);
            }
        }

        throw new \LogicException('Retry loop exited unexpectedly.');
    }

    private function completeIdempotency(string $key, string $fingerprint, string $transactionId): void
    {
        try {
            $this->idempotency->complete($key, $fingerprint, $transactionId);
        } catch (\Throwable $exception) {
            $this->logger->critical('Transfer committed but Redis idempotency cache could not be completed.', [
                'transaction_id' => $transactionId,
                'exception' => $exception,
            ]);
        }
    }

    private function fingerprint(TransferFundsCommand $command, Money $amount): string
    {
        return hash('sha256', json_encode([
            'from_account_id' => $command->fromAccountId,
            'to_account_id' => $command->toAccountId,
            'amount' => $amount->toDecimal(self::MONEY_SCALE),
            'currency' => strtoupper($command->currency),
        ], JSON_THROW_ON_ERROR));
    }
}
