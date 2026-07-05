<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Deploy\Runtime\FileSecret;
use Vortos\Secrets\Provider\SecretsProviderRegistry;

/**
 * Fail-closed gate that every declared file-shaped secret (G8) is present in the secret store before
 * the deploy tries to materialise it. A missing file secret would otherwise surface only at
 * materialise time, mid-cutover. Skips cleanly when no file secrets are declared (the store is not
 * touched, so a deployment without this feature never depends on it).
 */
final class FileSecretsCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly SecretsProviderRegistry $providers,
        private readonly string $secretsDriver = 'age',
    ) {}

    public function id(): string
    {
        return 'secrets.file_secrets_present';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Security;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        $fileSecrets = $context->definition->runtimeService->fileSecrets;

        if ($fileSecrets === []) {
            return PreflightFinding::skip(
                $this->id(),
                $this->category(),
                'no file-shaped secrets declared',
            );
        }

        $provider = $this->providers->provider($this->secretsDriver);
        $present = array_map(static fn ($key): string => $key->value(), $provider->list());

        $missing = [];
        foreach ($fileSecrets as $fileSecret) {
            /** @var FileSecret $fileSecret */
            if (!in_array($fileSecret->name, $present, true)) {
                $missing[] = $fileSecret->name;
            }
        }

        if ($missing !== []) {
            sort($missing);

            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'declared file-shaped secret(s) are missing from the store',
                'missing: ' . implode(', ', $missing),
                sprintf(
                    'Add the secret(s) with vortos:secrets:set (driver "%s"), or remove the '
                    . 'fileSecret(...) declaration from config/deploy.php.',
                    $this->secretsDriver,
                ),
            );
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            sprintf('all %d declared file-shaped secret(s) present in the store', count($fileSecrets)),
        );
    }
}
