<?php

declare(strict_types=1);

namespace App\Infrastructure\EventSubscriber;

use App\Domain\Exception\DomainException;
use InvalidArgumentException;
use JsonException;
use Predis\Connection\ConnectionException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onKernelException',
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $statusCode = $this->statusCodeFor($exception);

        if ($statusCode >= 500) {
            $this->logger->error('Unhandled API exception.', [
                'exception' => $exception,
            ]);
        }

        $event->setResponse(new JsonResponse([
            'error' => [
                'code' => $this->codeFor($exception, $statusCode),
                'message' => $this->messageFor($exception, $statusCode),
            ],
        ], $statusCode));
    }

    private function statusCodeFor(\Throwable $exception): int
    {
        return match (true) {
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof JsonException,
            $exception instanceof InvalidArgumentException,
            $exception instanceof DomainException => JsonResponse::HTTP_BAD_REQUEST,
            $exception instanceof RuntimeException && str_contains($exception->getMessage(), ConnectionException::class) => JsonResponse::HTTP_SERVICE_UNAVAILABLE,
            $exception instanceof RuntimeException => JsonResponse::HTTP_CONFLICT,
            $exception instanceof ConnectionException => JsonResponse::HTTP_SERVICE_UNAVAILABLE,
            default => JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    private function codeFor(\Throwable $exception, int $statusCode): string
    {
        return match (true) {
            $exception instanceof JsonException => 'invalid_json',
            $exception instanceof InvalidArgumentException => 'invalid_request',
            $exception instanceof DomainException => 'domain_error',
            $exception instanceof RuntimeException && str_contains($exception->getMessage(), ConnectionException::class) => 'redis_unavailable',
            $exception instanceof ConnectionException => 'redis_unavailable',
            $exception instanceof RuntimeException => 'conflict',
            $statusCode === JsonResponse::HTTP_NOT_FOUND => 'not_found',
            default => 'server_error',
        };
    }

    private function messageFor(\Throwable $exception, int $statusCode): string
    {
        if ($this->codeFor($exception, $statusCode) === 'redis_unavailable') {
            return 'Redis is unavailable. Start Redis locally on 127.0.0.1:6379 or update REDIS_URL.';
        }

        return $statusCode >= 500 ? 'Internal server error.' : $exception->getMessage();
    }
}
