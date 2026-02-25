<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class Phase6RealtimeAuthException extends RuntimeException
{
    public function __construct(
        private readonly string $reasonCode,
        string $message = 'Forbidden.',
        private readonly int $status = 403,
    ) {
        parent::__construct($message);
    }

    public static function denied(string $reasonCode, string $message = 'Forbidden.'): self
    {
        return new self($reasonCode, $message, 403);
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }

    public function status(): int
    {
        return $this->status;
    }
}
