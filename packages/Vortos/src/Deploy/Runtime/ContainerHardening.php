<?php

declare(strict_types=1);

namespace Vortos\Deploy\Runtime;

/**
 * What a container may do to the machine it runs on, beyond running its own process.
 *
 * ## Why every color needs it
 *
 * Docker's defaults give a container fourteen Linux capabilities (raw sockets, chown, setuid,
 * mknod, …), let a setuid binary inside it raise its privileges, and leave its root filesystem
 * writable. None of that is needed to serve PHP, and each of it is something an attacker with
 * code execution in the app would use next: a writable filesystem to plant a persistent backdoor
 * that survives the request, setuid to climb from "app" to root, raw sockets to spoof traffic on
 * the shared network. Locked down, a compromise is confined to the process's own memory and the
 * few directories it genuinely writes, and it dies with the container.
 *
 * ## Writable paths
 *
 * A read-only root filesystem still has to give the application somewhere to write. Each declared
 * path becomes an in-memory tmpfs — empty at every start, gone at every stop, never on disk. They
 * are declared rather than guessed: the set was measured on production with "docker diff", and a
 * path left out fails loudly at boot instead of being silently writable.
 */
final readonly class ContainerHardening
{
    /**
     * @param list<string> $writablePaths absolute paths mounted as tmpfs over a read-only root
     */
    private function __construct(
        public bool $locked,
        public array $writablePaths,
    ) {
        foreach ($writablePaths as $path) {
            if (!str_starts_with($path, '/') || str_contains($path, ':') || preg_match('/\s/', $path) === 1) {
                throw new \InvalidArgumentException(sprintf(
                    'ContainerHardening writable paths must be absolute paths with no colon or whitespace; got "%s".',
                    $path,
                ));
            }
        }

        if (count(array_unique($writablePaths)) !== count($writablePaths)) {
            throw new \InvalidArgumentException('ContainerHardening writable paths must not repeat.');
        }
    }

    /** Docker's defaults: every default capability, a writable root, setuid allowed. */
    public static function none(): self
    {
        return new self(false, []);
    }

    /**
     * No capabilities, no privilege escalation, a read-only root filesystem, and a tmpfs at each
     * path the process writes.
     *
     * @param list<string> $writablePaths
     */
    public static function locked(array $writablePaths): self
    {
        return new self(true, $writablePaths);
    }

    /**
     * The compose keys this adds to a service.
     *
     * @return array<string, mixed>
     */
    public function composeKeys(): array
    {
        if (!$this->locked) {
            return [];
        }

        $keys = [
            'cap_drop' => ['ALL'],
            'security_opt' => ['no-new-privileges:true'],
            'read_only' => true,
        ];

        if ($this->writablePaths !== []) {
            $keys['tmpfs'] = $this->writablePaths;
        }

        return $keys;
    }

    /** @return array{locked: bool, writable_paths: list<string>} */
    public function toArray(): array
    {
        return ['locked' => $this->locked, 'writable_paths' => $this->writablePaths];
    }
}
