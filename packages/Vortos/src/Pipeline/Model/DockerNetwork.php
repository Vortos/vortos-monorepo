<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

/**
 * A Docker network the deploy creates if it is missing, and whether its one-shots join it.
 *
 * ## Why the deploy owns these
 *
 * The colors and the edge declare their networks `external`, so something has to create them
 * before either starts, and a network created by hand is a step a rebuilt box forgets. The deploy
 * script runs first on every release, so it makes each one exist — idempotently, and never altering
 * one that is already there.
 *
 * ## Internal networks
 *
 * `internal` networks have no route off the host. They carry control traffic — the Docker API
 * proxy, the edge's admin API — that nothing should be able to reach from outside, and that has no
 * reason to reach out. A container that also needs the internet joins a second, ordinary network.
 *
 * ## Joined by the deploy
 *
 * The deploy one-shots run migrations (the app network), drive Docker (the proxy's network) and
 * reconfigure the edge (its admin network). Each network they must reach says so here, so the
 * one-shot's `--network` flags come from the same declaration that creates the network.
 */
final readonly class DockerNetwork
{
    public function __construct(
        public string $name,
        public bool $internal,
        public bool $joinedByDeploy,
    ) {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Docker network name "%s" is not valid.', $name));
        }
    }

    /** An internal network the deploy one-shots join (the Docker API proxy's, the edge admin's). */
    public static function control(string $name): self
    {
        return new self($name, internal: true, joinedByDeploy: true);
    }

    /** An ordinary network only services join (the edge's serving network). */
    public static function serving(string $name): self
    {
        return new self($name, internal: false, joinedByDeploy: false);
    }

    /** @return array{name: string, internal: bool, joined_by_deploy: bool} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'internal' => $this->internal, 'joined_by_deploy' => $this->joinedByDeploy];
    }
}
