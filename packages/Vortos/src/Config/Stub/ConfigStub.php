<?php

declare(strict_types=1);

namespace Vortos\Config\Stub;

final readonly class ConfigStub
{
    /**
     * @param string $module Module name — used as the output filename (e.g. 'auth' → config/auth.php)
     * @param string $path   Absolute path to the stub file shipped with the module
     */
    public function __construct(
        public string $module,
        public string $path,
    ) {}
}
