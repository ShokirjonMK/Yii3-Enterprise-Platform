<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

final class ValidationException extends DomainException
{
    /**
     * @param array<string, list<string>> $errors field => messages
     */
    public function __construct(private readonly array $errors, string $message = 'Validation failed.')
    {
        parent::__construct($message);
    }

    public static function single(string $field, string $message): self
    {
        return new self([$field => [$message]], $message);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function details(): array
    {
        return $this->errors;
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'validation_failed';
    }
}
