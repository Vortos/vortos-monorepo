<?php

declare(strict_types=1);

namespace Vortos\Audit\Doctor;

/**
 * One diagnostic result. `status` is ok | warn | fail; warn is for degraded-but-running
 * conditions (e.g. unsigned chains) and fail for broken/misconfigured ones.
 */
final readonly class AuditDoctorCheck
{
    public const OK   = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    public function __construct(
        public string $name,
        public string $status,
        public string $message,
    ) {}

    public static function ok(string $name, string $message): self
    {
        return new self($name, self::OK, $message);
    }

    public static function warn(string $name, string $message): self
    {
        return new self($name, self::WARN, $message);
    }

    public static function fail(string $name, string $message): self
    {
        return new self($name, self::FAIL, $message);
    }
}
