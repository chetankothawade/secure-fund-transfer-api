<?php

declare(strict_types=1);

namespace App\Infrastructure\Audit;

interface AuditLoggerInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function record(string $transactionId, string $event, array $payload): void;
}
