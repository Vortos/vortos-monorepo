<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Emitter\Capability;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

enum EmitterCapability: string implements CapabilityKey
{
    case GithubActions = 'github_actions';
    case GitlabCi = 'gitlab_ci';
    case Matrix = 'matrix';
    case Oidc = 'oidc';
    case ShaPinning = 'sha_pinning';
    case ReusableWorkflows = 'reusable_workflows';
    case BuildNativeArch = 'build_native_arch';

    public function key(): string
    {
        return $this->value;
    }
}
