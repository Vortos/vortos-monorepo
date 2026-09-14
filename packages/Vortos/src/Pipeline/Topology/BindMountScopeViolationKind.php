<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Topology;

enum BindMountScopeViolationKind: string implements TopologyViolationKind
{
    /** A service bind-mounts a host path the pipeline neither syncs nor declares as a host system bind. */
    case Undeclared = 'undeclared';

    /** A declared path is mounted by a service outside its allowed audience. */
    case ServiceNotAllowed = 'service_not_allowed';

    /** An allowed service never mounts the path: an audience wider than the topology, or a dead declaration. */
    case Unconsumed = 'unconsumed';

    /** The mount grants more than the declaration: read-write where read-only was declared or required. */
    case AccessWiderThanDeclared = 'access_wider_than_declared';

    /** A bind of the deploy dir or one of its ancestors with no tmpfs shadow over the deploy dir inside the container. */
    case DeployDirExposed = 'deploy_dir_exposed';

    /** The mount cannot be resolved statically, so which host path the service receives cannot be proven. */
    case Unverifiable = 'unverifiable';
}
