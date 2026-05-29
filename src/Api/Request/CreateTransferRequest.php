<?php

declare(strict_types=1);

namespace App\Api\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * DTO populated from the create-transfer request body before validation.
 */
final class CreateTransferRequest
{
    /**
     * UUID of the account that will be debited.
     */
    #[Assert\NotBlank]
    #[Assert\Uuid(strict: false)]
    public ?string $from_account_id = null;

    /**
     * UUID of the account that will be credited.
     */
    #[Assert\NotBlank]
    #[Assert\Uuid(strict: false)]
    public ?string $to_account_id = null;

    /**
     * Positive decimal amount accepted as a JSON number or string.
     */
    #[Assert\NotBlank]
    #[Assert\Positive]
    public int|float|string|null $amount = null;

    /**
     * ISO 4217 currency code for the transfer amount.
     */
    #[Assert\NotBlank]
    #[Assert\Currency]
    public ?string $currency = null;

    /**
     * Client-generated key used to make transfer retries idempotent.
     */
    #[Assert\NotBlank]
    public ?string $idempotency_key = null;
}
