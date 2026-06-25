<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle\StateBackend;

use Vortos\Iac\Exception\LocalStateForbiddenException;

final class StateBackendValidator
{
    private const REMOTE_REQUIRED_ENVIRONMENTS = ['prod', 'production', 'staging'];

    public function validate(StateBackendProvider $provider, string $environment): void
    {
        if (!$provider->isRemote() && in_array($environment, self::REMOTE_REQUIRED_ENVIRONMENTS, true)) {
            throw LocalStateForbiddenException::forEnvironment($environment);
        }
    }
}
