<?php

declare(strict_types=1);

namespace App\Infrastructure\Audit;

use Doctrine\DBAL\Connection;

final readonly class DoctrineAuditLogger implements AuditLoggerInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(string $transactionId, string $event, array $payload): void
    {
        $this->connection->insert('audit_log', [
            'transaction_id' => $transactionId,
            'event' => $event,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }
}
