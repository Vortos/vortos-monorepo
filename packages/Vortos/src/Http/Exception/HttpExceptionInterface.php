<?php

declare(strict_types=1);

namespace Vortos\Http\Exception;

interface HttpExceptionInterface extends \Throwable
{
    public function getStatusCode(): int;

    /** @return array<string, string|string[]> */
    public function getHeaders(): array;
}
