<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle;

final readonly class IacWorkspace
{
    private const ENV_PATTERN = '/^[a-z][a-z0-9-]*$/';

    public function __construct(
        public string $environment,
        public string $workingDir,
        public string $stateKey,
    ) {
        if (!preg_match(self::ENV_PATTERN, $environment)) {
            throw new \InvalidArgumentException(sprintf(
                "Invalid environment name '%s' — must match %s.",
                $environment,
                self::ENV_PATTERN,
            ));
        }

        if ($workingDir === '') {
            throw new \InvalidArgumentException('Working directory must not be empty.');
        }

        if ($stateKey === '') {
            throw new \InvalidArgumentException('State key must not be empty.');
        }
    }

    public static function forEnvironment(string $environment, string $workingDir): self
    {
        return new self($environment, $workingDir, $environment);
    }
}
