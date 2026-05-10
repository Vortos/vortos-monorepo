<?php

declare(strict_types=1);

namespace Vortos\Mcp\Server;

final class StdioTransport
{
    public function read(): ?array
    {
        $line = fgets(STDIN);
        if ($line === false) {
            return null;
        }

        $line = trim($line);
        if ($line === '') {
            return null;
        }

        try {
            return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    public function write(array $message): void
    {
        fwrite(STDOUT, json_encode($message, JSON_THROW_ON_ERROR) . "\n");
        fflush(STDOUT);
    }
}
