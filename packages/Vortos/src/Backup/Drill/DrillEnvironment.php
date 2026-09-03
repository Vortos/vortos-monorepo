<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill;

final readonly class DrillEnvironment
{
    /**
     * @param array<string, mixed> $options provisioner-specific handles the restore target needs
     */
    public function __construct(
        public string $dsn,
        public string $label,
        /**
         * Opaque to the drill runner, which forwards it verbatim into {@see Restore\RestoreRequest}.
         *
         * A logical restore needs nothing beyond a DSN — it connects and pipes a dump. A
         * point-in-time restore does: it has to lay a data directory down INSIDE a specific
         * container that is not running yet, so it needs that container's identity and the path its
         * cluster lives at, neither of which a DSN can express. Passing them as an opaque bag keeps
         * the provisioner seam from growing PITR-shaped methods that every other provisioner would
         * have to implement and never call.
         */
        public array $options = [],
    ) {}

    /**
     * A required option, or a clear failure.
     *
     * Provisioner and restore target agree on these keys by convention, so a mismatch is a wiring
     * bug that must surface as itself rather than as a null threaded three frames deeper.
     */
    public function requireOption(string $key): string
    {
        $value = $this->options[$key] ?? null;

        if (!\is_string($value) || $value === '') {
            throw new \RuntimeException(sprintf(
                "Drill environment '%s' is missing the required option '%s' — the provisioner and "
                . 'the restore target disagree about how this environment is described.',
                $this->label,
                $key,
            ));
        }

        return $value;
    }
}
