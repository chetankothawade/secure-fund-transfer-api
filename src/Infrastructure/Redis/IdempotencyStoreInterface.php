<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

interface IdempotencyStoreInterface
{
    public function start(string $key): IdempotencyResult;

    public function complete(string $key, string $transactionId): void;

    public function release(string $key): void;
}
