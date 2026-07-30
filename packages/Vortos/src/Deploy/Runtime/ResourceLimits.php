<?php

declare(strict_types=1);

namespace Vortos\Deploy\Runtime;

/**
 * Kernel resource limits applied to the blue/green app and worker containers.
 *
 * ## Why this exists
 *
 * A container inherits the Docker daemon's own soft limits, and on a stock daemon that means
 * 'nofile' = 1024. Every open connection costs one descriptor, so 1024 is a hard ceiling of roughly
 * 500–900 concurrent clients per container once TLS, upstream sockets, log files, and the Postgres /
 * Redis / broker pools are accounted for. Nothing in the stack reports that ceiling: the failure mode
 * is 'accept: too many open files' in a log nobody is watching, and connections being refused while
 * CPU and memory both look idle.
 *
 * That ceiling is invisible until the app stops holding a PHP thread per live connection. While
 * long-lived streams occupied the worker pool the thread count ran out first (see
 * docs/plans/realtime-transport-mercure.md); with streams moved onto the Mercure hub, where idle
 * connections are goroutines rather than threads, descriptors become the first wall reached.
 *
 * ## Why it is declared here rather than set on the box
 *
 * The app colors are created by the deploy from a generated compose file, not from a checked-in one,
 * so there is no file an operator can edit that survives the next release. Raising the daemon's
 * default in '/etc/docker/daemon.json' would work but is host state — invisible to the repo,
 * un-reviewed, and silently absent on a rebuilt or second box. Declaring it on the runtime spec keeps
 * it in the same place as every other statement about the container's real shape.
 *
 * ## Choosing values
 *
 * The soft limit is what processes actually get; the hard limit is the ceiling they may raise
 * themselves to. Both are set to the same value because nothing here raises its own limit at runtime,
 * and a hard limit above the soft one only creates the illusion of headroom. The default of 65535 is
 * far above any plausible per-container connection count and far below the host's hard limit
 * (524288 on the current target) and 'fs.nr_open', so it applies cleanly rather than failing the
 * container start.
 */
final readonly class ResourceLimits
{
    /**
     * Chosen to be comfortably above any realistic per-container connection count while staying
     * well inside a normal host hard limit, so raising it can never be the reason a color fails
     * to start.
     */
    public const DEFAULT_NOFILE = 65535;

    /**
     * Below this a container is at or near the stock daemon default, which is the condition this
     * class exists to prevent. Declaring a value in that range is far more likely to be a typo or
     * a misunderstanding than a deliberate choice, so it is rejected rather than honoured.
     */
    public const MIN_NOFILE = 4096;

    public function __construct(
        public int $nofileSoft = self::DEFAULT_NOFILE,
        public int $nofileHard = self::DEFAULT_NOFILE,
    ) {
        foreach (['nofileSoft' => $nofileSoft, 'nofileHard' => $nofileHard] as $field => $value) {
            if ($value < self::MIN_NOFILE) {
                throw new \InvalidArgumentException(sprintf(
                    'ResourceLimits.%s must be at least %d — a lower value leaves the container at '
                    . 'roughly the stock daemon default, which caps concurrent connections at a few '
                    . 'hundred and fails as "too many open files" rather than as backpressure. Got %d.',
                    $field,
                    self::MIN_NOFILE,
                    $value,
                ));
            }
        }

        if ($nofileSoft > $nofileHard) {
            throw new \InvalidArgumentException(sprintf(
                'ResourceLimits.nofileSoft (%d) must not exceed nofileHard (%d): the kernel refuses a '
                . 'soft limit above the hard ceiling, so the container would fail to start.',
                $nofileSoft,
                $nofileHard,
            ));
        }
    }

    /** The framework default — see the class docblock for how the value was chosen. */
    public static function defaults(): self
    {
        return new self();
    }

    /**
     * An explicit descriptor limit. Pass one value to set both soft and hard, which is almost always
     * what is wanted since nothing in the app image raises its own limit at runtime.
     */
    public static function nofile(int $soft, ?int $hard = null): self
    {
        return new self(nofileSoft: $soft, nofileHard: $hard ?? $soft);
    }

    /**
     * The Compose 'ulimits' mapping.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'nofile' => [
                'soft' => $this->nofileSoft,
                'hard' => $this->nofileHard,
            ],
        ];
    }
}
