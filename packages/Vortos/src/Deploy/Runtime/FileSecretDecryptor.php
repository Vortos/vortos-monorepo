<?php

declare(strict_types=1);

namespace Vortos\Deploy\Runtime;

use Vortos\Secrets\Provider\SecretsProviderInterface;
use Vortos\Secrets\Value\SecretKey;

/**
 * The single sanctioned reveal point for file-shaped secrets (G8).
 *
 * The secret-redaction discipline forbids ->reveal() in Console/Preflight/Runner code. Materialising
 * a file secret genuinely requires the plaintext (to write it to tmpfs), so that reveal is isolated
 * here, in one auditable place, and the revealed {@see \Vortos\Secrets\Value\SecretValue} is wiped
 * immediately after the plaintext is extracted.
 */
final class FileSecretDecryptor
{
    public function plaintext(FileSecret $fileSecret, SecretsProviderInterface $provider): string
    {
        $value = $provider->get(SecretKey::fromString($fileSecret->name));

        try {
            return $value->reveal();
        } finally {
            $value->wipe();
        }
    }
}
