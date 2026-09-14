<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Topology;

enum EnvFileScopeViolationKind: string implements TopologyViolationKind
{
    /** A service reads an env file the pipeline neither delivers nor declares as a root of trust. */
    case Undeclared = 'undeclared';

    /** A declared secret env file is mounted by a service outside its allowed audience. */
    case ServiceNotAllowed = 'service_not_allowed';

    /** An allowed service never mounts the file: a dead secret, or an audience wider than the topology. */
    case Unconsumed = 'unconsumed';

    /** An app-wide runtime env file is listed after a scoped one, so its values silently win. */
    case ShadowedByRuntimeEnv = 'shadowed_by_runtime_env';

    /** A scoped secret is marked `required: false`, so a missing file would boot the service without it. */
    case Optional = 'optional';

    /** The entry cannot be resolved statically, so which file the service receives cannot be proven. */
    case Unverifiable = 'unverifiable';
}
