<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use Predis\ClientInterface;
use JsonException;
use RuntimeException;

final readonly class IdempotencyService implements IdempotencyStoreInterface
{
    private const TTL_SECONDS = 86400;
    private const PROCESSING_VALUE = 'processing';

    public function __construct(private ClientInterface $redis)
    {
    }

    public function start(string $key, string $fingerprint): IdempotencyResult
    {
        if ($key === '') {
            throw new RuntimeException('Idempotency key is required.');
        }

        $redisKey = $this->redisKey($key);
        $reserved = $this->redis->set(
            $redisKey,
            $this->encode(['status' => self::PROCESSING_VALUE, 'fingerprint' => $fingerprint]),
            'EX',
            self::TTL_SECONDS,
            'NX',
        );

        if ((string) $reserved === 'OK') {
            return IdempotencyResult::reserved();
        }

        $cachedValue = $this->redis->get($redisKey);

        if ($cachedValue === null) {
            return IdempotencyResult::processing();
        }

        $payload = $this->decode((string) $cachedValue);

        if ($payload === null) {
            return IdempotencyResult::cached((string) $cachedValue);
        }

        if (($payload['fingerprint'] ?? null) !== $fingerprint) {
            return IdempotencyResult::fingerprintMismatch();
        }

        if (($payload['status'] ?? null) === self::PROCESSING_VALUE) {
            return IdempotencyResult::processing();
        }

        if (is_string($payload['transaction_id'] ?? null) && $payload['transaction_id'] !== '') {
            return IdempotencyResult::cached($payload['transaction_id']);
        }

        return IdempotencyResult::processing();
    }

    public function complete(string $key, string $fingerprint, string $transactionId): void
    {
        $this->redis->set($this->redisKey($key), $this->encode([
            'status' => 'completed',
            'fingerprint' => $fingerprint,
            'transaction_id' => $transactionId,
        ]), 'EX', self::TTL_SECONDS);
    }

    public function release(string $key): void
    {
        $this->redis->del([$this->redisKey($key)]);
    }

    private function redisKey(string $key): string
    {
        return 'idempotency:'.$key;
    }

    /**
     * @param array<string, string> $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $value): ?array
    {
        try {
            $payload = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }
}
