<?php

declare(strict_types=1);

namespace App\Api\Request;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateTransferRequest
{
    #[Assert\NotBlank]
    #[Assert\Uuid(strict: false)]
    public ?string $from_account_id = null;

    #[Assert\NotBlank]
    #[Assert\Uuid(strict: false)]
    public ?string $to_account_id = null;

    #[Assert\NotBlank]
    #[Assert\Positive]
    public int|float|string|null $amount = null;

    #[Assert\NotBlank]
    #[Assert\Currency]
    public ?string $currency = null;

    #[Assert\NotBlank]
    public ?string $idempotency_key = null;
}
