<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Application\Command\TransferFundsCommand;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

final readonly class TransferController
{
    #[Route('/transfers', name: 'api_transfers_create', methods: ['POST'])]
    public function __invoke(Request $request, MessageBusInterface $messageBus): JsonResponse
    {
        $payload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $envelope = $messageBus->dispatch(new TransferFundsCommand(
            fromAccountId: (string) ($payload['from_account_id'] ?? ''),
            toAccountId: (string) ($payload['to_account_id'] ?? ''),
            amount: (float) ($payload['amount'] ?? 0),
            currency: (string) ($payload['currency'] ?? ''),
            idempotencyKey: (string) $request->headers->get('Idempotency-Key', $payload['idempotency_key'] ?? '')
        ));
        $transactionId = $envelope->last(HandledStamp::class)?->getResult();

        return new JsonResponse([
            'transaction_id' => $transactionId,
            'status' => $transactionId === null ? 'duplicate' : 'accepted',
        ], JsonResponse::HTTP_ACCEPTED);
    }
}
