<?php

declare(strict_types=1);

namespace AlgerianCommerce\API;

use RuntimeException;
use Throwable;

/**
 * An error that is safe to return to an API client.
 *
 * Anything thrown that is *not* an ApiException is treated as unexpected and
 * mapped to a generic internal error, so implementation detail never leaks
 * into a response body.
 */
final class ApiException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $statusCode = 400,
        private readonly array $details = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return Response::errorPayload($this->errorCode, $this->getMessage(), $this->details);
    }

    /** @param array<string, mixed> $details */
    public static function invalidRequest(string $message = 'The request is invalid.', array $details = []): self
    {
        return new self('invalid_request', $message, 400, $details);
    }

    public static function unauthenticated(string $message = 'Authentication is required.'): self
    {
        return new self('unauthenticated', $message, 401);
    }

    public static function forbidden(string $message = 'You are not allowed to perform this action.'): self
    {
        return new self('forbidden', $message, 403);
    }

    public static function notFound(string $message = 'The resource was not found.'): self
    {
        return new self('not_found', $message, 404);
    }

    /** @param array<string, mixed> $details */
    public static function conflict(string $message = 'The resource is in a conflicting state.', array $details = []): self
    {
        return new self('conflict', $message, 409, $details);
    }

    public static function internal(string $message = 'An unexpected error occurred.', ?Throwable $previous = null): self
    {
        return new self('internal_error', $message, 500, [], $previous);
    }
}
