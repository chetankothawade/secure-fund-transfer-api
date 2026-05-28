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
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class TransferFundsHandler
{
    private const MONEY_SCALE = 4;

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

        $idempotencyResult = $this->idempotency->start($command->idempotencyKey);

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
            throw new InvalidArgumentException('A transfer with this idempotency key is still processing.');
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
            $this->idempotency->release($command->idempotencyKey);

            throw $exception;
        }

        $this->idempotency->complete($command->idempotencyKey, $transaction->id);
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
