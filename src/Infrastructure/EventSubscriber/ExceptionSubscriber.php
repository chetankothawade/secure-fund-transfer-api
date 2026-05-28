<?php

declare(strict_types=1);

namespace App\Infrastructure\EventSubscriber;

use App\Api\Response\ProblemJsonFactory;
use App\Domain\Exception\AccountNotFoundException;
use App\Domain\Exception\CurrencyMismatchException;
use App\Domain\Exception\InsufficientFundsException;
use InvalidArgumentException;
use JsonException;
use Predis\Connection\ConnectionException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final readonly class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private ProblemJsonFactory $problemJsonFactory,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onKernelException',
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $this->unwrap($event->getThrowable());
        $statusCode = $this->statusCodeFor($exception);

        if ($statusCode >= 500) {
            $this->logger->error('Unhandled API exception.', [
                'exception' => $exception,
            ]);
        }

        $event->setResponse($this->problemJsonFactory->create(
            $statusCode,
            $this->titleFor($exception, $statusCode),
            $this->messageFor($exception, $statusCode),
            $this->typeFor($exception, $statusCode),
        ));
    }

    private function statusCodeFor(\Throwable $exception): int
    {
        return match (true) {
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof AccountNotFoundException => Response::HTTP_NOT_FOUND,
            $exception instanceof InsufficientFundsException => Response::HTTP_CONFLICT,
            $exception instanceof CurrencyMismatchException => Response::HTTP_UNPROCESSABLE_ENTITY,
            $exception instanceof JsonException,
            $exception instanceof InvalidArgumentException => Response::HTTP_BAD_REQUEST,
            $exception instanceof RuntimeException && str_contains($exception->getMessage(), ConnectionException::class) => Response::HTTP_SERVICE_UNAVAILABLE,
            $exception instanceof RuntimeException => Response::HTTP_CONFLICT,
            $exception instanceof ConnectionException => Response::HTTP_SERVICE_UNAVAILABLE,
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    private function titleFor(\Throwable $exception, int $statusCode): string
    {
        return match (true) {
            $exception instanceof AccountNotFoundException => 'Account not found',
            $exception instanceof InsufficientFundsException => 'Insufficient funds',
            $exception instanceof CurrencyMismatchException => 'Currency mismatch',
            $exception instanceof JsonException => 'Invalid JSON',
            $exception instanceof InvalidArgumentException => 'Invalid request',
            $exception instanceof RuntimeException && str_contains($exception->getMessage(), ConnectionException::class),
            $exception instanceof ConnectionException => 'Redis unavailable',
            $exception instanceof RuntimeException => 'Conflict',
            $statusCode === Response::HTTP_NOT_FOUND => 'Not found',
            default => 'Internal server error',
        };
    }

    private function typeFor(\Throwable $exception, int $statusCode): string
    {
        $slug = match (true) {
            $exception instanceof AccountNotFoundException => 'account-not-found',
            $exception instanceof InsufficientFundsException => 'insufficient-funds',
            $exception instanceof CurrencyMismatchException => 'currency-mismatch',
            $exception instanceof JsonException => 'invalid-json',
            $exception instanceof InvalidArgumentException => 'invalid-request',
            $exception instanceof RuntimeException && str_contains($exception->getMessage(), ConnectionException::class),
            $exception instanceof ConnectionException => 'redis-unavailable',
            $exception instanceof RuntimeException => 'conflict',
            default => $statusCode >= 500 ? 'server-error' : 'request-error',
        };

        return 'https://example.com/problems/'.$slug;
    }

    private function messageFor(\Throwable $exception, int $statusCode): string
    {
        if (
            $exception instanceof ConnectionException
            || ($exception instanceof RuntimeException && str_contains($exception->getMessage(), ConnectionException::class))
        ) {
            return 'Redis is unavailable. Start Redis locally on 127.0.0.1:6379 or update REDIS_URL.';
        }

        return $statusCode >= 500 ? 'Internal server error.' : $exception->getMessage();
    }

    private function unwrap(\Throwable $exception): \Throwable
    {
        if ($exception instanceof HandlerFailedException && $exception->getPrevious() !== null) {
            return $exception->getPrevious();
        }

        return $exception;
    }
}
