<?php

declare(strict_types=1);

namespace Vortos\Foundation\Health\Attribute;

use Attribute;
use Vortos\Foundation\Health\Contract\HealthCheckKind;

#[Attribute(Attribute::TARGET_CLASS)]
final class AsHealthCheck
{
    public function __construct(
        public readonly bool $critical = true,
        public readonly int $timeoutMs = 5000,
        /**
         * Which surface the bridged probe reports on. Defaults to Readiness so existing checks keep
         * their behaviour; declare Monitoring for any shared external dependency, and see
         * {@see HealthCheckKind} for why that is not a matter of taste.
         *
         * `critical` still applies within the chosen kind — it decides fail vs warn, not whether the
         * result gates traffic. A Monitoring check never gates traffic however critical it is.
         */
        public readonly HealthCheckKind $kind = HealthCheckKind::Readiness,
    ) {}
}
