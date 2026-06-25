<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Oci;

use Vortos\Deploy\Exception\CommandFailedException;
use Vortos\Deploy\Execution\CommandRunnerInterface;
use Vortos\Deploy\Oci\ImageSignerInterface;
use Vortos\Deploy\Registry\ContainerRegistryInterface;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Registry\RegistryCapability;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('oci')]
final class OciRegistry implements ContainerRegistryInterface
{
    public function __construct(
        private readonly CommandRunnerInterface $runner,
        private readonly ImageSignerInterface $signer,
        private readonly ?string $registryAuth = null,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            RegistryCapability::DigestPin->value => true,
            RegistryCapability::MultiArch->value => true,
            RegistryCapability::VulnerabilityScan->value => false,
            RegistryCapability::ImageSigning->value => false,
        ]);
    }

    public function push(ImageReference $image): ImageReference
    {
        $this->authenticate();

        $argv = ['docker', 'push', $image->toString()];
        $result = $this->runner->run($argv, redactTokens: $this->redactTokens());
        $result->throwOnFailure('docker push');

        $digest = $this->digestFor($image);
        $pushed = $image->withDigest($digest);

        $this->signer->sign($pushed);

        return $pushed;
    }

    public function pull(ImageReference $image): void
    {
        $this->authenticate();

        if (!$image->isDigestPinned()) {
            throw new \InvalidArgumentException('Pull requires a digest-pinned image reference.');
        }

        $argv = ['docker', 'pull', $image->toString()];
        $result = $this->runner->run($argv, redactTokens: $this->redactTokens());
        $result->throwOnFailure('docker pull');
    }

    public function tag(ImageReference $image, string $tag): ImageReference
    {
        $tagged = $image->withTag($tag);
        $argv = ['docker', 'tag', $image->toString(), $tagged->toString()];
        $result = $this->runner->run($argv);
        $result->throwOnFailure('docker tag');

        return $tagged;
    }

    public function digestFor(ImageReference $image): string
    {
        $digest = $this->tryDaemonlessDigest($image);
        if ($digest !== null) {
            return $digest;
        }

        $argv = ['docker', 'buildx', 'imagetools', 'inspect', '--raw', $image->toString()];
        $result = $this->runner->run($argv, redactTokens: $this->redactTokens());
        $result->throwOnFailure('digest inspection');

        return $this->extractDigestFromInspect($result->stdout, $image);
    }

    private function tryDaemonlessDigest(ImageReference $image): ?string
    {
        try {
            $argv = ['crane', 'digest', $image->toString()];
            $result = $this->runner->run($argv, redactTokens: $this->redactTokens());
            if ($result->isSuccess()) {
                return $this->validateDigest(trim($result->stdout));
            }
        } catch (\Throwable) {
        }

        try {
            $argv = ['skopeo', 'inspect', '--raw', sprintf('docker://%s', $image->toString())];
            $result = $this->runner->run($argv, redactTokens: $this->redactTokens());
            if ($result->isSuccess()) {
                $hash = hash('sha256', $result->stdout);

                return $this->validateDigest('sha256:' . $hash);
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function extractDigestFromInspect(string $output, ImageReference $image): string
    {
        $hash = hash('sha256', $output);

        return $this->validateDigest('sha256:' . $hash);
    }

    private function validateDigest(string $digest): string
    {
        if (!preg_match('/^sha256:[a-f0-9]{64}$/', $digest)) {
            throw new \RuntimeException(sprintf('Invalid digest format: %s', $digest));
        }

        return $digest;
    }

    private function authenticate(): void
    {
        if ($this->registryAuth === null) {
            return;
        }

        $argv = ['docker', 'login', '--username', '_json_key', '--password-stdin'];
        $result = $this->runner->run($argv, stdin: $this->registryAuth, redactTokens: $this->redactTokens());
        $result->throwOnFailure('docker login');
    }

    /** @return list<string> */
    private function redactTokens(): array
    {
        if ($this->registryAuth === null) {
            return [];
        }

        return [$this->registryAuth];
    }
}
