<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Application\Command\TransferFundsCommand;
use App\Application\Handler\TransferFundsHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class TransferController
{
    #[Route('/transfers', name: 'api_transfers_create', methods: ['POST'])]
    public function __invoke(Request $request, TransferFundsHandler $handler): JsonResponse
    {
        $payload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $transactionId = $handler->handle(new TransferFundsCommand(
            fromAccountId: (string) ($payload['from_account_id'] ?? ''),
            toAccountId: (string) ($payload['to_account_id'] ?? ''),
            amount: (string) ($payload['amount'] ?? ''),
            currency: (string) ($payload['currency'] ?? ''),
            idempotencyKey: (string) $request->headers->get('Idempotency-Key', $payload['idempotency_key'] ?? '')
        ));

        return new JsonResponse([
            'transaction_id' => $transactionId,
            'status' => 'accepted',
        ], JsonResponse::HTTP_ACCEPTED);
    }
}
