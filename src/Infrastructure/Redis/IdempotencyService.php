<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use Predis\ClientInterface;
use RuntimeException;

final readonly class IdempotencyService implements IdempotencyStoreInterface
{
    private const TTL_SECONDS = 86400;
    private const PROCESSING_VALUE = 'processing';

    public function __construct(private ClientInterface $redis)
    {
    }

    public function start(string $key): IdempotencyResult
    {
        if ($key === '') {
            throw new RuntimeException('Idempotency key is required.');
        }

        $redisKey = $this->redisKey($key);
        $reserved = $this->redis->set($redisKey, self::PROCESSING_VALUE, 'EX', self::TTL_SECONDS, 'NX');

        if ((string) $reserved === 'OK') {
            return IdempotencyResult::reserved();
        }

        $cachedValue = $this->redis->get($redisKey);

        if ($cachedValue === self::PROCESSING_VALUE || $cachedValue === null) {
            return IdempotencyResult::processing();
        }

        return IdempotencyResult::cached((string) $cachedValue);
    }

    public function complete(string $key, string $transactionId): void
    {
        $this->redis->set($this->redisKey($key), $transactionId, 'EX', self::TTL_SECONDS);
    }

    public function release(string $key): void
    {
        $this->redis->del([$this->redisKey($key)]);
    }

    private function redisKey(string $key): string
    {
        return 'idempotency:'.$key;
    }
}
