<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Topology;

enum ImageProvenanceViolationKind: string implements TopologyViolationKind
{
    /** The image is a mutable tag: what runs is whatever the tag pointed at when the host last pulled. */
    case Unpinned = 'unpinned';

    /** The service builds from source on the host instead of running a released, verified image. */
    case BuiltOnHost = 'built_on_host';

    /** The service names no image at all. */
    case MissingImage = 'missing_image';

    /** An auxiliary image variable used by a service the declaration does not list. */
    case ServiceNotAllowed = 'service_not_allowed';

    /** A service declared to run an auxiliary image does not reference its variable. */
    case Unconsumed = 'unconsumed';

    /** The reference interpolates something other than a declared auxiliary image variable, so what runs cannot be proven. */
    case Unverifiable = 'unverifiable';

    /** A digest from the application repository on a service no pinned image declares: nothing built or will verify it. */
    case UndeclaredOwnImage = 'undeclared_own_image';
}
