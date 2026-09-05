<?php

declare(strict_types=1);

namespace Vortos\Deploy\Topology;

use RuntimeException;

/**
 * Converges the host's compose topology onto the version that shipped inside the release image.
 *
 * WHY THIS EXISTS. The deploy pipeline builds and signs an image, verifies the signature, and runs
 * the cutover — but it never copied the compose file. So /opt/vortos/docker-compose.prod.yaml was
 * whatever a human last edited by hand, and the repository copy was documentation that nothing read.
 * Measured on this installation before the first sync: the live file was three weeks stale and the
 * repository held thirteen non-comment lines that had been committed, reviewed and deployed green
 * while never once taking effect — among them a Kafka byte-retention cap written specifically to
 * stop unbounded growth. The topology said one thing and the box did another, and nothing anywhere
 * reported the difference.
 *
 * WHY IT READS FROM THE IMAGE RATHER THAN COPYING FROM CI. The compose file is part of the
 * repository, so it is already inside the release image, and that image has its cosign signature
 * verified immediately before this runs. Taking the file from there means the topology inherits
 * exactly the same supply-chain guarantee as the code — no second transfer channel, no second thing
 * to trust, nothing that can be tampered with between signing and applying. An scp from the runner
 * would instead trust whatever happened to be on that runner's disk at that moment, which is a
 * strictly weaker claim about a file that describes how the database is run.
 *
 * WHAT IT DELIBERATELY DOES NOT DO: apply anything. Writing the desired state and CONVERGING to it
 * are separate acts, and conflating them here would be dangerous in two distinct ways. The app and
 * worker services are mid-cutover — the blue/green deploy runs moments later and owns them, so a
 * compose up from here would race it. And the datastores must never be recreated implicitly: a
 * change as innocuous-looking as a logging option requires recreating the container, and doing that
 * to write_db without anyone deciding to is an outage delivered by a config tidy-up.
 *
 * So this is the "sync" half of GitOps and not the "auto-sync" half: the desired state lands on the
 * box and the drift is reported. Convergence stays a decision someone makes.
 */
final class ComposeTopologySync
{
    /**
     * Services whose definition must never change without a human deciding to recreate them.
     *
     * Recreating any of these is a data-availability event, not a config change — and compose will
     * happily recreate a container to apply something as small as a log-rotation option.
     */
    public const DEFAULT_STATEFUL_SERVICES = ['write_db', 'redis', 'kafka'];

    /**
     * @param list<string> $statefulServices
     */
    public function __construct(
        private readonly string $sourcePath,
        private readonly string $targetPath,
        private readonly array $statefulServices = self::DEFAULT_STATEFUL_SERVICES,
    ) {}

    public function sync(bool $apply): ComposeSyncResult
    {
        $desired = $this->readSource();

        if (!is_file($this->targetPath)) {
            // A fresh box has no topology yet, so there is nothing to diff and nothing to protect.
            if ($apply) {
                $this->writeAtomically($desired, backup: false);
            }

            return ComposeSyncResult::installed($this->targetPath, $apply);
        }

        $live = (string) file_get_contents($this->targetPath);

        if ($this->normalise($live) === $this->normalise($desired)) {
            return ComposeSyncResult::alreadyInSync($this->targetPath);
        }

        // Which services differ, so the report names them rather than saying "something changed in
        // a 35 KB file". The stateful ones are what an operator has to act on deliberately.
        $changed = $this->changedServices($live, $desired);
        $stateful = array_values(array_intersect($changed, $this->statefulServices));

        $backup = null;
        if ($apply) {
            $backup = $this->writeAtomically($desired, backup: true);
        }

        return ComposeSyncResult::drifted(
            path: $this->targetPath,
            applied: $apply,
            backupPath: $backup,
            changedServices: $changed,
            statefulServices: $stateful,
        );
    }

    /**
     * The topology as shipped, validated before it is allowed anywhere near the host file.
     *
     * Fail-closed on anything unreadable or structurally wrong: replacing a working topology with a
     * broken one is the single worst outcome available to this class, and it would not be noticed
     * until the next time somebody ran compose — quite possibly during an incident.
     */
    private function readSource(): string
    {
        if (!is_file($this->sourcePath) || !is_readable($this->sourcePath)) {
            throw new RuntimeException(sprintf(
                'Compose topology not found in the release image at %s — refusing to touch the host '
                . 'file. The image should carry the repository copy; if it does not, the build has '
                . 'changed shape and this step must be fixed rather than skipped.',
                $this->sourcePath,
            ));
        }

        $contents = (string) file_get_contents($this->sourcePath);

        if (trim($contents) === '') {
            throw new RuntimeException('Compose topology in the release image is empty — refusing to write it.');
        }

        if ($this->servicesIn($contents) === []) {
            throw new RuntimeException(
                'Compose topology in the release image declares no services — refusing to write it. '
                . 'That is either a truncated file or a different format, and either way replacing a '
                . 'working topology with it would be undetectable until the next compose invocation.',
            );
        }

        return $contents;
    }

    /**
     * Write via a temporary file and rename(2), which is atomic within a filesystem.
     *
     * A partial write here leaves the host unable to start anything, so the live path must never be
     * observed half-written — not even for the microseconds a direct write would take. The previous
     * contents are kept beside it, timestamped, because the fastest safe rollback is a copy.
     *
     * @return string|null the backup path, when one was taken
     */
    private function writeAtomically(string $contents, bool $backup): ?string
    {
        $backupPath = null;

        if ($backup) {
            $backupPath = sprintf('%s.bak.%s', $this->targetPath, date('Ymd-His'));
            if (!@copy($this->targetPath, $backupPath)) {
                throw new RuntimeException(sprintf(
                    'Could not back up the live topology to %s — refusing to overwrite it without a '
                    . 'rollback point.',
                    $backupPath,
                ));
            }
        }

        $temporary = $this->targetPath . '.incoming';

        if (@file_put_contents($temporary, $contents) === false) {
            throw new RuntimeException(sprintf('Could not stage the new topology at %s.', $temporary));
        }

        // Match whatever the live file already is, so a sync never quietly widens who can read or
        // write the topology.
        $mode = @fileperms($this->targetPath);
        if ($mode !== false) {
            @chmod($temporary, $mode & 0o7777);
        }

        if (!@rename($temporary, $this->targetPath)) {
            @unlink($temporary);

            throw new RuntimeException(sprintf('Could not move the new topology into place at %s.', $this->targetPath));
        }

        return $backupPath;
    }

    /**
     * Service names whose block differs between the two files.
     *
     * A deliberately coarse comparison — the block of lines belonging to each service — because the
     * question being answered is "does an operator need to look at this service", not "what exactly
     * changed". Comments and blank lines are ignored so a reworded explanation is not reported as a
     * topology change.
     *
     * @return list<string>
     */
    private function changedServices(string $live, string $desired): array
    {
        $liveBlocks = $this->serviceBlocks($live);
        $desiredBlocks = $this->serviceBlocks($desired);

        $names = array_unique([...array_keys($liveBlocks), ...array_keys($desiredBlocks)]);
        sort($names);

        $changed = [];
        foreach ($names as $name) {
            if (($liveBlocks[$name] ?? null) !== ($desiredBlocks[$name] ?? null)) {
                $changed[] = $name;
            }
        }

        return $changed;
    }

    /**
     * @return array<string, string> service name => its normalised block
     */
    private function serviceBlocks(string $yaml): array
    {
        $blocks = [];
        $current = null;
        $lines = [];

        foreach (explode("\n", $yaml) as $line) {
            if (preg_match('/^  ([A-Za-z0-9._-]+):\s*$/', $line, $m) === 1 && $this->inServicesSection($yaml, $line)) {
                if ($current !== null) {
                    $blocks[$current] = implode("\n", $lines);
                }
                $current = $m[1];
                $lines = [];
                continue;
            }

            // A top-level key ends the services section.
            if ($current !== null && preg_match('/^[A-Za-z0-9._-]+:/', $line) === 1) {
                $blocks[$current] = implode("\n", $lines);
                $current = null;
                $lines = [];
                continue;
            }

            if ($current !== null) {
                $trimmed = trim($line);
                if ($trimmed !== '' && !str_starts_with($trimmed, '#')) {
                    $lines[] = rtrim($line);
                }
            }
        }

        if ($current !== null) {
            $blocks[$current] = implode("\n", $lines);
        }

        return $blocks;
    }

    /**
     * Whether the two-space key is a service rather than a nested mapping elsewhere in the file.
     *
     * Compose files carry other top-level maps — volumes:, networks:, and the x- extension
     * anchors this deployment uses for shared logging — whose children sit at the same indentation
     * as a service name. Counting them as services would report phantom drift.
     */
    private function inServicesSection(string $yaml, string $line): bool
    {
        $offset = strpos($yaml, $line);
        if ($offset === false) {
            return false;
        }

        $before = substr($yaml, 0, $offset);
        $lastTopLevel = null;

        foreach (explode("\n", $before) as $candidate) {
            if (preg_match('/^([A-Za-z0-9._-]+):/', $candidate, $m) === 1) {
                $lastTopLevel = $m[1];
            }
        }

        return $lastTopLevel === 'services';
    }

    /** @return list<string> */
    private function servicesIn(string $yaml): array
    {
        return array_keys($this->serviceBlocks($yaml));
    }

    /** Comments and trailing whitespace are not topology. */
    private function normalise(string $yaml): string
    {
        $kept = [];

        foreach (explode("\n", $yaml) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $kept[] = rtrim($line);
        }

        return implode("\n", $kept);
    }
}
