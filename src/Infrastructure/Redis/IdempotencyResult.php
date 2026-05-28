<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

final readonly class IdempotencyResult
{
    private function __construct(
        public bool $reserved,
        public ?string $transactionId,
        public bool $processing,
        public bool $fingerprintMismatch,
    ) {
    }

    public static function reserved(): self
    {
        return new self(true, null, false, false);
    }

    public static function cached(string $transactionId): self
    {
        return new self(false, $transactionId, false, false);
    }

    public static function processing(): self
    {
        return new self(false, null, true, false);
    }

    public static function fingerprintMismatch(): self
    {
        return new self(false, null, false, true);
    }
}
