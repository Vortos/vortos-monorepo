<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential;

interface SshCertificateAuthorityInterface
{
    /**
     * @param int $ttlSeconds Requested TTL — CA may clamp to its own maximum
     */
    public function sign(
        string $publicKey,
        OidcToken $oidcToken,
        int $ttlSeconds,
    ): SignedSshCertificate;
}
