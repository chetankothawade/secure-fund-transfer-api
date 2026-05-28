<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use App\Domain\Exception\CurrencyMismatchException;
use InvalidArgumentException;

final readonly class Money
{
    private const DEFAULT_SCALE = 2;

    public int $minorUnits;

    public string $currency;

    public function __construct(int $minorUnits, string $currency)
    {
        if ($minorUnits < 0) {
            throw new InvalidArgumentException('Amount cannot be negative.');
        }

        $currency = strtoupper($currency);

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Currency must be an ISO 4217 code.');
        }

        $this->minorUnits = $minorUnits;
        $this->currency = $currency;
    }

    public static function fromFloat(float $amount, string $currency, int $scale = self::DEFAULT_SCALE): self
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('Amount cannot be negative.');
        }

        return new self((int) round($amount * (10 ** $scale)), strtoupper($currency));
    }

    public static function fromDecimal(string $amount, string $currency, int $scale = self::DEFAULT_SCALE): self
    {
        $amount = trim($amount);

        if (! preg_match('/^\d+(?:\.\d+)?$/', $amount)) {
            throw new InvalidArgumentException('Amount must be a positive decimal value.');
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException(sprintf('Amount cannot have more than %d decimal places.', $scale));
        }

        $minorUnits = ((int) $whole) * (10 ** $scale);
        $minorUnits += (int) str_pad($fraction, $scale, '0');

        if ($minorUnits <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }

        return new self($minorUnits, strtoupper($currency));
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        if ($other->isGreaterThan($this)) {
            throw new InvalidArgumentException('Amount cannot be negative.');
        }

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits > $other->minorUnits;
    }

    public function toFloat(int $scale = self::DEFAULT_SCALE): float
    {
        return $this->minorUnits / (10 ** $scale);
    }

    public function toDecimal(int $scale = self::DEFAULT_SCALE): string
    {
        $factor = 10 ** $scale;
        $whole = intdiv($this->minorUnits, $factor);
        $fraction = $this->minorUnits % $factor;

        return sprintf('%d.%0'.$scale.'d', $whole, $fraction);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatchException::create();
        }
    }
}
