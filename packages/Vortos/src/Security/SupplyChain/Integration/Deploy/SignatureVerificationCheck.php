<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Integration\Deploy;

use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Security\SupplyChain\Model\ArtifactDigest;
use Vortos\Security\SupplyChain\Model\Signature\VerificationPolicy;
use Vortos\Security\SupplyChain\Port\ArtifactSignerRegistry;

final class SignatureVerificationCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly ArtifactSignerRegistry $signerRegistry,
        private readonly string $signerKey,
        private readonly ?VerificationPolicy $policy = null,
    ) {}

    public function id(): string
    {
        return 'security.signature';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Security;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        $imageDigest = $context->desiredManifest->imageDigest;

        if ($this->policy === null) {
            return PreflightFinding::skip(
                $this->id(),
                $this->category(),
                'No verification policy configured — supply-chain signature check skipped.',
            );
        }

        if (!$this->signerRegistry->has($this->signerKey)) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                sprintf('Artifact signer "%s" is not registered.', $this->signerKey),
                sprintf('registered: [%s]', implode(', ', $this->signerRegistry->keys())),
                'Install the signer driver or correct the selection in config.',
            );
        }

        $signer = $this->signerRegistry->signer($this->signerKey);
        $digest = new ArtifactDigest($imageDigest);
        $result = $signer->verify($digest, $this->policy);

        if (!$result->ok) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                sprintf('Image %s failed signature verification.', $imageDigest),
                implode('; ', $result->reasons),
                'Sign the image with a trusted key or fix the verification policy.',
            );
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            sprintf('Image %s signature verified.', $imageDigest),
        );
    }
}
