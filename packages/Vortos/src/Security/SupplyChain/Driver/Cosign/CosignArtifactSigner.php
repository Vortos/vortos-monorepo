<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Driver\Cosign;

use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\Secrets\Value\SecretValue;
use Vortos\Security\SupplyChain\Capability\SupplyChainCapabilityKey;
use Vortos\Security\SupplyChain\Driver\Process\ProcessFailedException;
use Vortos\Security\SupplyChain\Driver\Process\ProcessRunnerInterface;
use Vortos\Security\SupplyChain\Model\ArtifactDigest;
use Vortos\Security\SupplyChain\Model\Signature\Signature;
use Vortos\Security\SupplyChain\Model\Signature\SignatureScheme;
use Vortos\Security\SupplyChain\Model\Signature\VerificationPolicy;
use Vortos\Security\SupplyChain\Model\Signature\VerificationResult;
use Vortos\Security\SupplyChain\Model\SupplyChainException;
use Vortos\Security\SupplyChain\Port\ArtifactSignerInterface;

#[AsDriver('cosign')]
final class CosignArtifactSigner implements ArtifactSignerInterface
{
    public function __construct(
        private readonly ProcessRunnerInterface $processRunner,
        private readonly int $timeoutSeconds = 120,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            SupplyChainCapabilityKey::Signing->value => true,
            SupplyChainCapabilityKey::KeylessSigning->value => true,
            SupplyChainCapabilityKey::RekorTransparency->value => true,
        ]);
    }

    public function sign(ArtifactDigest $digest): Signature
    {
        try {
            $output = $this->processRunner->run(
                ['cosign', 'sign', '--yes', $digest->toString()],
                ['COSIGN_EXPERIMENTAL' => '1'],
                $this->timeoutSeconds,
            );
        } catch (ProcessFailedException $e) {
            throw SupplyChainException::scanFailed('cosign sign failed: ' . $e->getMessage());
        }

        if (!$output->isSuccessful()) {
            throw ProcessFailedException::fromOutput('cosign', $output);
        }

        $logIndex = $this->extractRekorLogIndex($output->stderr . $output->stdout);

        return new Signature(
            scheme: SignatureScheme::KeylessFulcio,
            payload: SecretValue::fromString($output->stdout),
            rekorLogIndex: $logIndex,
        );
    }

    public function verify(ArtifactDigest $digest, VerificationPolicy $policy): VerificationResult
    {
        $command = ['cosign', 'verify'];

        if ($policy->isKeyless()) {
            $command[] = '--certificate-oidc-issuer=' . $policy->issuer;
            $command[] = '--certificate-identity-regexp=' . $policy->sanRegex;
        }

        $command[] = $digest->toString();

        try {
            $output = $this->processRunner->run($command, ['COSIGN_EXPERIMENTAL' => '1'], $this->timeoutSeconds);
        } catch (ProcessFailedException $e) {
            return VerificationResult::failure(['cosign verify process failed: ' . $e->getMessage()]);
        }

        if (!$output->isSuccessful()) {
            $stderr = trim($output->stderr);

            return VerificationResult::failure([
                $stderr !== '' ? $stderr : 'cosign verify exited with code ' . $output->exitCode,
            ]);
        }

        return VerificationResult::success();
    }

    private function extractRekorLogIndex(string $output): ?int
    {
        if (preg_match('/tlog entry created with index:\s*(\d+)/i', $output, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }
}
