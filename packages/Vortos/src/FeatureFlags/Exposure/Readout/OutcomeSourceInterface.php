<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure\Readout;

use DateTimeImmutable;

/**
 * The application's half of an experiment: who converted.
 *
 * The framework owns the exposure log — who saw which variant, and when. It cannot own the
 * outcome, because an outcome is domain vocabulary: "completed signup", "paid an entry fee",
 * "published a tournament". Only the application knows where those live and what counts as
 * one.
 *
 * ## Return raw identities, not hashes
 *
 * Implementations return the same analytics identity the application reports events under —
 * the raw user id. The readout pseudonymises them itself, with the same pepper the ledger
 * was written with, and joins on the result.
 *
 * That division is the point. The pepper stays inside the framework, so an application
 * implementing this interface never handles it, cannot log it, and cannot accidentally store
 * a hash computed with the wrong one — which would produce an experiment where no subject
 * ever converts, and no error anywhere.
 *
 * ## Idempotence and windows
 *
 * The window is closed at both ends and expressed in UTC, matching the ledger's day grain.
 * An implementation must count a subject once no matter how many times they converted: the
 * test measures the proportion of subjects who converted, not the number of conversions, and
 * returning a subject twice silently inflates one arm.
 */
interface OutcomeSourceInterface
{
    /**
     * Raw analytics identities that converted on `$metric` within the window.
     *
     * @param  string            $metric application-defined outcome name
     * @param  DateTimeImmutable $from   inclusive, UTC
     * @param  DateTimeImmutable $to     inclusive, UTC
     * @return iterable<string>  each identity at most once
     */
    public function convertedSubjects(string $metric, DateTimeImmutable $from, DateTimeImmutable $to): iterable;

    /**
     * Outcome names this source can answer for, so a command can list them and a typo can be
     * rejected up front rather than returning an empty arm that looks like a real zero.
     *
     * @return list<string>
     */
    public function availableMetrics(): array;
}
