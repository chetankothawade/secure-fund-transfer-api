<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

interface IdempotencyStoreInterface
{
    public function reserve(string $key, int $ttlSeconds): void;
}
