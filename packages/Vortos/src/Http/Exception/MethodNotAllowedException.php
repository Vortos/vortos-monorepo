<?php

declare(strict_types=1);

namespace Vortos\Http\Exception;

class MethodNotAllowedException extends HttpException
{
    /** @param string[] $allowedMethods */
    public function __construct(array $allowedMethods, string $message = 'Method Not Allowed', ?\Throwable $previous = null)
    {
        parent::__construct(405, $message, ['Allow' => implode(', ', $allowedMethods)], $previous);
    }
}
