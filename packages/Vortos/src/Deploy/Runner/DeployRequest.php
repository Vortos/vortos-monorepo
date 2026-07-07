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
        public ?string $imageRepository = null,
        public string $actorId = 'unknown',
        public ActorIdentitySource $actorIdentitySource = ActorIdentitySource::Local,
        /**
         * R8-1: when true, run 'vortos:migrate:publish' in the project tree before the doctor gate so
         * newly-shipped module stubs become app migrations. Opt-in (off by default); only ever runs in
         * Live mode. The default fail-closed posture is the UnpublishedStubCheck refusing the deploy.
         */
        public bool $autoPublishMigrations = false,
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

        if ($imageRepository !== null && preg_match('#^[a-z0-9]+(?:[._-][a-z0-9]+)*(?::[0-9]+)?(?:/[a-z0-9]+(?:[._-][a-z0-9]+)*)*$#', $imageRepository) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Image repository must be a lowercase OCI repository with no tag/digest suffix, got "%s".',
                $imageRepository,
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
