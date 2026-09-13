<?php

declare(strict_types=1);

namespace Vortos\Backup\Service\Process;

use Vortos\Foundation\Process\SensitiveFile;
use Vortos\Foundation\Secret\SecretValue;

/**
 * The `--config` YAML the MongoDB database tools read sensitive options from (`uri`, `password`), so a
 * credential-bearing URI never appears on the command line.
 */
final class MongoToolConfig
{
    public static function forUri(SecretValue $uri): SensitiveFile
    {
        // Single-quoted YAML scalar: the only escape is a doubled quote, so no URI character can end it.
        $yaml = "uri: '" . str_replace("'", "''", $uri->reveal()) . "'\n";

        return new SensitiveFile(SecretValue::fromString($yaml), '--config=');
    }
}
