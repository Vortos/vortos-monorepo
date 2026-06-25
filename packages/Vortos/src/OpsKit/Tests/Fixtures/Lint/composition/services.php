<?php

declare(strict_types=1);

// Fixture — a composition-root file legitimately wires a provider-named driver.
// It is exempted by PATH (not namespace), proving exemptPathFragments works.

use App\Edge\Driver\Caddy\CaddyEdgeDriver;

return static function (): object {
    return new CaddyEdgeDriver();
};
