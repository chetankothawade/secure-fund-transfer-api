<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\Response\ProblemJsonFactory;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AccountController
{
    #[Route('/accounts/{id}', name: 'api_accounts_show', methods: ['GET'])]
    public function show(string $id, Connection $connection): JsonResponse
    {
        $account = $connection->fetchAssociative(
            'SELECT id, owner_name, balance, currency, version, created_at FROM accounts WHERE id = ?',
            [$id]
        );

        if ($account === false) {
            return new JsonResponse([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Account not found.',
                ],
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'id' => $account['id'],
            'owner_name' => $account['owner_name'],
            'balance' => $account['balance'],
            'currency' => $account['currency'],
            'version' => (int) $account['version'],
            'created_at' => $account['created_at'],
        ]);
    }

    #[Route('/accounts/{id}/balance', name: 'api_accounts_balance', methods: ['GET'])]
    public function balance(string $id, Connection $connection, ProblemJsonFactory $problemJsonFactory): JsonResponse
    {
        $account = $connection->fetchAssociative(
            'SELECT balance, currency FROM accounts WHERE id = ?',
            [$id]
        );

        if ($account === false) {
            return $problemJsonFactory->create(
                JsonResponse::HTTP_NOT_FOUND,
                'Account not found',
                sprintf('Account "%s" was not found.', $id),
                'https://example.com/problems/account-not-found',
            );
        }

        return new JsonResponse([
            'balance' => $account['balance'],
            'currency' => $account['currency'],
        ]);
    }
}
