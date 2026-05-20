<?php

declare(strict_types=1);

namespace Vortos\Http\Exception;

class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Not Found', ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, [], $previous);
    }
}
