<?php

declare(strict_types=1);

namespace Vortos\Deploy\Exception;

final class ManifestSignatureInvalidException extends DeployException
{
    public static function create(string $detail = ''): self
    {
        $message = 'Manifest signature verification failed — the manifest may have been tampered with.';
        if ($detail !== '') {
            $message .= ' ' . $detail;
        }

        return new self($message);
    }
}
