<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight;

/**
 * A single fail-closed preflight gate.
 *
 * Implementations are tagged services collected by {@see
 * \Vortos\Deploy\DependencyInjection\Compiler\CollectPreflightChecksPass}, so the
 * doctor is extensible: a future block adds a gate by tagging a service — no edit to
 * {@see DeployDoctor}.
 *
 * Contract:
 *  - {@see check()} returns a {@see PreflightFinding} (Pass | Fail | Skip).
 *  - It MUST be read-only — no mutation of remote/local state, no minting of
 *    credentials. A preflight that mutates breaks the "doctor is read-only" guarantee.
 *  - It SHOULD prefer returning a Fail finding over throwing, but if it does throw,
 *    the doctor converts the throw into a Fail (fail-closed) — a check never silently
 *    passes because it could not complete.
 */
interface PreflightCheckInterface
{
    /** Stable, dotted identifier, e.g. 'credential.issuable'. Used for sorting + CI parsing. */
    public function id(): string;

    public function category(): PreflightCategory;

    public function check(PreflightContext $context): PreflightFinding;
}
