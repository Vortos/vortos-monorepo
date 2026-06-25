<?php

declare(strict_types=1);

namespace Vortos\Deploy\Runner;

use Vortos\Deploy\Audit\ActorIdentitySource;

final readonly class DeployRequest
{
    public function __construct(
        public string $env,
        public DeployExecutionMode $mode = DeployExecutionMode::Live,
        public bool $assumeYes = false,
        public bool $resume = false,
        public ?string $targetBuildId = null,
        public ?string $imageDigest = null,
        public string $actorId = 'unknown',
        public ActorIdentitySource $actorIdentitySource = ActorIdentitySource::Local,
    ) {
        if ($env === '') {
            throw new \InvalidArgumentException('Deploy request env must not be empty.');
        }

        if ($imageDigest !== null && preg_match('/^sha256:[a-f0-9]{64}$/', $imageDigest) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Image digest must match sha256:<64 hex>, got "%s".',
                $imageDigest,
            ));
        }
    }

    public static function dryRun(string $env): self
    {
        return new self($env, DeployExecutionMode::DryRun);
    }

    public static function live(string $env, bool $assumeYes = false, bool $resume = false): self
    {
        return new self($env, DeployExecutionMode::Live, $assumeYes, $resume);
    }

    public function isDryRun(): bool
    {
        return $this->mode === DeployExecutionMode::DryRun;
    }
}
