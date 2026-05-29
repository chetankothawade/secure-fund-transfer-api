<?php

declare(strict_types=1);

namespace App\Api\Response;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Builds RFC 7807-compatible JSON error responses for API clients.
 */
final readonly class ProblemJsonFactory
{
    /**
     * Create an application/problem+json response with optional extension members.
     *
     * @param array<string, mixed> $extra
     */
    public function create(
        int $status,
        string $title,
        string $detail,
        string $type = 'about:blank',
        array $extra = [],
    ): JsonResponse {
        return new JsonResponse([
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            ...$extra,
        ], $status, ['Content-Type' => 'application/problem+json']);
    }

    /**
     * Convert Symfony validation violations into the API's standard problem response.
     */
    public function validation(ConstraintViolationListInterface $violations): JsonResponse
    {
        $errors = [];

        foreach ($violations as $violation) {
            $errors[] = [
                'field' => $violation->getPropertyPath(),
                'message' => $violation->getMessage(),
            ];
        }

        return $this->create(
            Response::HTTP_BAD_REQUEST,
            'Invalid request body',
            'One or more request fields failed validation.',
            'https://example.com/problems/validation-error',
            ['errors' => $errors],
        );
    }
}
