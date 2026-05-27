<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use Predis\ClientInterface;
use RuntimeException;

final readonly class IdempotencyService implements IdempotencyStoreInterface
{
    public function __construct(private ClientInterface $redis)
    {
    }

    public function reserve(string $key, int $ttlSeconds): void
    {
        if ($key === '') {
            throw new RuntimeException('Idempotency key is required.');
        }

        $reserved = $this->redis->set('idempotency:'.$key, '1', 'EX', $ttlSeconds, 'NX');

        if ((string) $reserved !== 'OK') {
            throw new RuntimeException('Duplicate idempotency key.');
        }
    }
}
