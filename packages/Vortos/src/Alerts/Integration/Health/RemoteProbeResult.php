<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Health;

/**
 * One probe result as reported by the node that owns it.
 *
 * Deliberately not {@see \Vortos\Health\Probe\ProbeResult}: this crossed a network as JSON and its
 * status is whatever string the owner sent. Keeping it a separate, dumber type means a malformed or
 * unrecognised status cannot be mistaken for a local probe's typed verdict — {@see isFailing()}
 * answers only for the one value that means failure, and anything it does not recognise is not
 * treated as a failure.
 */
final readonly class RemoteProbeResult
{
    public function __construct(
        public string $name,
        public string $status,
        public string $detail = '',
    ) {}

    /**
     * Only an explicit `fail` is a failure.
     *
     * `warn` is not: a Monitoring probe warns about trajectory — a certificate three weeks out, a
     * WAL bill trending wrong — and a `health_probe_failing` rule is asking whether the dependency
     * is DOWN. Treating a warning as a failure here would page for things the probe deliberately
     * chose not to escalate.
     *
     * An unrecognised status is not a failure either. A body this class could not parse properly, or
     * a future status word, must not turn into a page for a dependency nobody has said is broken.
     */
    public function isFailing(): bool
    {
        return strtolower($this->status) === 'fail';
    }
}
