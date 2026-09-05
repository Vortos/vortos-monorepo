<?php

declare(strict_types=1);

namespace Vortos\Deploy\Topology;

/**
 * What a topology sync found and did — reported rather than merely logged, because drift that
 * nobody is told about is exactly the condition this whole step exists to end.
 */
final readonly class ComposeSyncResult
{
    /**
     * @param list<string> $changedServices  services whose definition differs from the live file
     * @param list<string> $statefulServices the subset of those that cannot be converged implicitly
     */
    private function __construct(
        public string $status,
        public string $path,
        public bool $applied,
        public ?string $backupPath = null,
        public array $changedServices = [],
        public array $statefulServices = [],
    ) {}

    public static function alreadyInSync(string $path): self
    {
        return new self('in_sync', $path, applied: false);
    }

    public static function installed(string $path, bool $applied): self
    {
        return new self('installed', $path, applied: $applied);
    }

    /**
     * @param list<string> $changedServices
     * @param list<string> $statefulServices
     */
    public static function drifted(
        string $path,
        bool $applied,
        ?string $backupPath,
        array $changedServices,
        array $statefulServices,
    ): self {
        return new self('drifted', $path, $applied, $backupPath, $changedServices, $statefulServices);
    }

    /**
     * Whether an operator has to do something.
     *
     * True only for stateful services, and only once the file has actually been written: those
     * cannot be converged by the deploy — recreating them is a data-availability decision — so the
     * box is now running a topology that differs from the one on disk until someone acts.
     */
    public function needsManualConvergence(): bool
    {
        return $this->applied && $this->statefulServices !== [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'path' => $this->path,
            'applied' => $this->applied,
            'backup_path' => $this->backupPath,
            'changed_services' => $this->changedServices,
            'stateful_services_needing_manual_convergence' => $this->statefulServices,
        ];
    }

    public function summary(): string
    {
        return match ($this->status) {
            'in_sync' => sprintf('topology already matches the release image (%s)', $this->path),
            'installed' => $this->applied
                ? sprintf('topology installed on a host that had none (%s)', $this->path)
                : sprintf('topology absent at %s — would install (dry run)', $this->path),
            default => sprintf(
                '%s %d service(s): %s%s',
                $this->applied ? 'topology updated for' : 'topology drift in',
                \count($this->changedServices),
                implode(', ', $this->changedServices),
                $this->statefulServices === []
                    ? ''
                    : sprintf(
                        ' — %s need a deliberate recreate; the running containers keep their current '
                        . 'definition until someone does that',
                        implode(', ', $this->statefulServices),
                    ),
            ),
        };
    }
}
