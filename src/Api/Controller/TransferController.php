<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\Request\CreateTransferRequest;
use App\Api\Response\ProblemJsonFactory;
use App\Application\Command\TransferFundsCommand;
use JsonException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class TransferController
{
    #[Route('/transfers', name: 'api_transfers_create', methods: ['POST'])]
    public function __invoke(
        Request $request,
        MessageBusInterface $messageBus,
        DenormalizerInterface $serializer,
        ValidatorInterface $validator,
        ProblemJsonFactory $problemJsonFactory,
        LoggerInterface $logger,
        #[Autowire(service: 'limiter.transfers')]
        RateLimiterFactory $transferLimiter,
        #[Autowire('%env(TRANSFER_API_KEY)%')]
        string $apiKey,
    ): JsonResponse {
        $startedAt = microtime(true);

        if (! $this->isAuthorized($request, $apiKey)) {
            return $problemJsonFactory->create(
                JsonResponse::HTTP_UNAUTHORIZED,
                'Unauthorized',
                'A valid X-Api-Key header is required.',
                'https://example.com/problems/unauthorized',
            );
        }

        $limit = $transferLimiter->create($this->rateLimitKey($request))->consume();

        if (! $limit->isAccepted()) {
            return $problemJsonFactory->create(
                JsonResponse::HTTP_TOO_MANY_REQUESTS,
                'Too many transfer requests',
                'Transfer requests are limited to 30 per minute.',
                'http://localhost:8080/problems/rate-limit-exceeded',
            );
        }

        $body = $request->getContent();

        if ($body === '') {
            $body = '{}';
        }

        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $problemJsonFactory->create(
                JsonResponse::HTTP_BAD_REQUEST,
                'Invalid JSON',
                'Request body must be valid JSON.',
                'http://localhost:8080/problems/invalid-json',
            );
        }

        $payload['idempotency_key'] = $request->headers->get('Idempotency-Key', $payload['idempotency_key'] ?? '');

        /** @var CreateTransferRequest $transferRequest */
        $transferRequest = $serializer->denormalize($payload, CreateTransferRequest::class);
        $violations = $validator->validate($transferRequest);

        if ($violations->count() > 0) {
            return $problemJsonFactory->validation($violations);
        }

        $envelope = $messageBus->dispatch(new TransferFundsCommand(
            fromAccountId: (string) $transferRequest->from_account_id,
            toAccountId: (string) $transferRequest->to_account_id,
            amount: (string) $transferRequest->amount,
            currency: (string) $transferRequest->currency,
            idempotencyKey: (string) $transferRequest->idempotency_key,
        ));
        $transactionId = $envelope->last(HandledStamp::class)?->getResult();

        $logger->info('Transfer request completed.', [
            'transaction_id' => $transactionId,
            'user_id' => $this->userId($request),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return new JsonResponse([
            'transaction_id' => $transactionId,
        ], JsonResponse::HTTP_CREATED);
    }

    private function rateLimitKey(Request $request): string
    {
        return (string) (
            $this->userId($request)
            ?? 'anonymous:'.$request->getClientIp()
        );
    }

    private function userId(Request $request): ?string
    {
        return $request->getUser() ?? $request->headers->get('X-User-Id');
    }

    private function isAuthorized(Request $request, string $configuredApiKey): bool
    {
        $requestApiKey = $request->headers->get('X-Api-Key');

        return is_string($requestApiKey)
            && $requestApiKey !== ''
            && hash_equals($configuredApiKey, $requestApiKey);
    }
}
