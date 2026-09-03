<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Support;

use Vortos\Backup\Drill\Container\ContainerHandle;
use Vortos\Backup\Drill\Container\ContainerRuntimeInterface;
use Vortos\Backup\Drill\Container\ContainerSpec;

/**
 * A container runtime that records uploads and replays a scripted log.
 *
 * Named for what it does rather than `Fake…` because a differently-shaped double of the same
 * interface already lives inside ContainerizedDatabaseProvisionerTest; two classes with one name in
 * two namespaces is the kind of ambiguity that gets the wrong one imported.
 *
 * @internal
 */
final class RecordingContainerRuntime implements ContainerRuntimeInterface
{
    /** @var list<array{path: string, bytes: string}> */
    public array $uploads = [];

    /** @var list<string> */
    public array $started = [];

    /** @var list<string> */
    public array $removed = [];

    public int $orphanSweeps = 0;

    /**
     * Lines the container "logs", appended to on each poll.
     *
     * @var list<string>
     */
    public array $log = [];

    /**
     * Called on every logsSince() poll with the uploads seen so far, so a test can drive the
     * container's side of the conversation — the real one answers a delivered segment by asking for
     * the next.
     *
     * @var (callable(self): void)|null
     */
    public $onPoll = null;

    public function ensureImage(string $image): void {}

    public function create(ContainerSpec $spec): ContainerHandle
    {
        return new ContainerHandle('fake-id', $spec->name, $spec->name);
    }

    public function run(ContainerSpec $spec): ContainerHandle
    {
        $handle = $this->create($spec);
        $this->start($handle);

        return $handle;
    }

    public function start(ContainerHandle $handle): void
    {
        $this->started[] = $handle->id;
    }

    public function putArchive(ContainerHandle $handle, string $path, iterable $tarChunks): void
    {
        $bytes = '';
        foreach ($tarChunks as $chunk) {
            $bytes .= $chunk;
        }

        $this->uploads[] = ['path' => $path, 'bytes' => $bytes];
    }

    public function logsSince(ContainerHandle $handle, int $sinceUnixSeconds): string
    {
        if ($this->onPoll !== null) {
            ($this->onPoll)($this);
        }

        // Trailing newline, like a real container log: every COMPLETE line ends with one, and the
        // feeder relies on that to tell a finished line from a poll that landed mid-write.
        return $this->log === [] ? '' : implode("\n", $this->log) . "\n";
    }

    public function remove(ContainerHandle $handle): void
    {
        $this->removed[] = $handle->id;
    }

    public function removeOrphans(string $label, ?string $exceptId = null): int
    {
        $this->orphanSweeps++;

        return 0;
    }

    /**
     * Names of the files uploaded so far, read back out of the tar headers.
     *
     * @return list<string>
     */
    public function uploadedNames(): array
    {
        $names = [];

        foreach ($this->uploads as $upload) {
            for ($o = 0; $o + 512 <= \strlen($upload['bytes']); $o += 512) {
                $name = rtrim(substr($upload['bytes'], $o, 100), "\0");
                if ($name === '') {
                    break;
                }
                $names[] = $name;
                $size = (int) octdec(trim(rtrim(substr($upload['bytes'], $o + 124, 12), "\0")) ?: '0');
                $o += 512 * (int) ceil($size / 512);
            }
        }

        return $names;
    }
}
