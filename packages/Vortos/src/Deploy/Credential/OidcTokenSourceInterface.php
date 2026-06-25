<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential;

interface OidcTokenSourceInterface
{
    public function requestToken(string $audience): OidcToken;
}
