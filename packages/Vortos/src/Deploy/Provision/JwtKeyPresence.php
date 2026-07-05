<?php

declare(strict_types=1);

namespace Vortos\Deploy\Provision;

/**
 * Decides whether RS256 JWT signing keys are already present, mirroring config/auth.php's precedence
 * (B14). Keys can be supplied two ways and the immutable-image / deploy-in-image path uses the first:
 *
 *   1. env-content — JWT_PRIVATE_KEY / JWT_PUBLIC_KEY hold base64-encoded PEM, delivered via .env.prod
 *      (the correct posture for immutable images; there is no writable secrets dir to generate into);
 *   2. file-path   — JWT_PRIVATE_KEY_PATH / JWT_PUBLIC_KEY_PATH point at PEM files on disk (dev).
 *
 * The old presence check looked only at is_file() of the path vars, so an app fully configured with
 * env-content keys still read as "keys absent" and provisioning tried to generate file keys into a
 * non-writable dir. Treating env-content keys as present fixes that.
 *
 * Pure and side-effect free: it reads the environment through an injected reader so it is unit
 * testable without touching real getenv()/$_ENV state.
 */
final readonly class JwtKeyPresence
{
    /** @var \Closure(string): ?string */
    private \Closure $reader;

    /** @param (callable(string): ?string)|null $reader resolves an env var name to its value (or null) */
    public function __construct(?callable $reader = null)
    {
        $this->reader = $reader !== null
            ? $reader(...)
            : static function (string $name): ?string {
                $value = $_ENV[$name] ?? getenv($name);

                return $value === false ? null : (is_string($value) ? $value : null);
            };
    }

    /** True when signing keys are available by EITHER supply mode. */
    public function present(): bool
    {
        return $this->envContentPresent() || $this->filePathPresent();
    }

    /** True when JWT_PRIVATE_KEY and JWT_PUBLIC_KEY both hold non-empty content. */
    public function envContentPresent(): bool
    {
        return $this->nonEmpty('JWT_PRIVATE_KEY') && $this->nonEmpty('JWT_PUBLIC_KEY');
    }

    /** True when both path vars point at existing files. */
    public function filePathPresent(): bool
    {
        $private = $this->value('JWT_PRIVATE_KEY_PATH');
        $public = $this->value('JWT_PUBLIC_KEY_PATH');

        return $private !== null && $private !== '' && is_file($private)
            && $public !== null && $public !== '' && is_file($public);
    }

    /**
     * The directory generated keys should be written to when they are absent: the dirname of the
     * private-key path if one is configured, else the current working directory.
     */
    public function keyOutputDir(): string
    {
        $private = $this->value('JWT_PRIVATE_KEY_PATH');
        if ($private !== null && $private !== '') {
            return \dirname($private);
        }

        return getcwd() ?: '.';
    }

    private function value(string $name): ?string
    {
        return ($this->reader)($name);
    }

    private function nonEmpty(string $name): bool
    {
        $value = $this->value($name);

        return $value !== null && $value !== '';
    }
}
