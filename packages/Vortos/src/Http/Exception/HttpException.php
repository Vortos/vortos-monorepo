<?php

declare(strict_types=1);

namespace Vortos\Http\Exception;

class HttpException extends \RuntimeException implements HttpExceptionInterface
{
    /** @param array<string, string|string[]> $headers */
    public function __construct(
        private int $statusCode,
        string $message = '',
        private array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, string|string[]> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /** @param array<string, string|string[]> $headers */
    public function setHeaders(array $headers): void
    {
        $this->headers = $headers;
    }
}
