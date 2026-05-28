<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class CurrencyMismatchException extends DomainException
{
    public static function create(): self
    {
        return new self('Currency mismatch.');
    }
}
