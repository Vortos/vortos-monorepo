<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure\Readout;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Vortos\FeatureFlags\Exposure\ExposureRecord;
use Vortos\FeatureFlags\Exposure\Ledger\SubjectPseudonymiser;

/**
 * Computes an experiment result by joining the first-party exposure ledger to an
 * application-supplied outcome.
 *
 * This is the whole reason the ledger exists. Everything upstream — the observer, the
 * deduper, the flusher — is in service of being able to run this query over a *complete*
 * population rather than a consented, sampled one.
 *
 * ## The join happens on the raw ledger, not the rollup
 *
 * The rollup knows how many subjects saw each variant; it does not know *which*, because that
 * is exactly the dimension it collapsed. An outcome join needs per-subject rows, so a readout
 * can only cover the window the raw ledger still holds. That is a real constraint rather than
 * an oversight, and {@see self::readout()} refuses out of range rather than quietly returning
 * a truncated arm — a readout silently missing its first week is worse than no readout, since
 * the missing subjects are the earliest, who are systematically different from the latest.
 *
 * ## Subjects that appear in more than one arm are excluded
 *
 * A subject can legitimately see both variants: the flag's rules changed, their attributes
 * changed, or a rollout percentage moved. Whatever the cause, such a subject belongs to no
 * arm — counting them in both inflates every denominator, and picking one arbitrarily biases
 * whichever arm they land in. They are dropped and counted, and the count is surfaced as a
 * warning, because a large one means the experiment was not held still and the result should
 * be discarded rather than interpreted.
 */
final readonly class ExperimentReadout
{
    public function __construct(
        private Connection $connection,
        private SubjectPseudonymiser $pseudonymiser,
        private OutcomeSourceInterface $outcomes,
        private string $ledgerTable,
        private int $ledgerRetentionDays,
    ) {}

    /**
     * @param string $control the variant treated as the baseline. Defaults to the boolean
     *                        flag's off state, which is the control in the overwhelming
     *                        majority of rollouts.
     */
    public function readout(
        string $flag,
        string $metric,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        string $control = ExposureRecord::BOOL_FALSE,
    ): ReadoutResult {
        $utc  = new DateTimeZone('UTC');
        $from = $from->setTimezone($utc);
        $to   = $to->setTimezone($utc);

        $warnings = $this->windowWarnings($from, $to);

        $exposedByVariant = $this->exposedSubjects($flag, $from, $to);

        [$exposedByVariant, $crossArm] = $this->dropCrossArmSubjects($exposedByVariant);

        if ($crossArm > 0) {
            $warnings[] = sprintf(
                '%d subject(s) saw more than one variant and were excluded from every arm. The '
                . 'flag did not hold still for the whole window; if this is a large share of the '
                . 'population, discard this result rather than interpreting it.',
                $crossArm,
            );
        }

        $converted = $this->convertedHashes($metric, $from, $to);

        $variants = [];
        foreach ($exposedByVariant as $variant => $subjects) {
            $variants[] = new VariantResult(
                variant:     (string) $variant,
                subjects:    count($subjects),
                conversions: count(array_intersect_key($subjects, $converted)),
            );
        }

        usort($variants, static fn (VariantResult $a, VariantResult $b): int => $b->subjects <=> $a->subjects);

        $controlArm   = $this->arm($variants, $control);
        $treatmentArm = $this->treatmentArm($variants, $control);

        return $this->compare($flag, $metric, $from, $to, $variants, $controlArm, $treatmentArm, $warnings);
    }

    /**
     * Every subject hash exposed to the flag in the window, grouped by variant.
     *
     * Read from the raw ledger with the `day` range leading, so this is a primary-key prefix
     * scan rather than a table scan.
     *
     * @return array<string,array<string,true>> variant => set of subject hashes
     */
    private function exposedSubjects(string $flag, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT variant, subject_hash
             FROM ' . $this->ledgerTable . '
             WHERE flag = :flag AND day >= :from AND day <= :to',
            [
                'flag' => $flag,
                'from' => $from->format('Y-m-d'),
                'to'   => $to->format('Y-m-d'),
            ],
        );

        $byVariant = [];
        foreach ($rows as $row) {
            // Set-keyed rather than appended: the same subject legitimately appears on several
            // days, and an arm counts subjects, not subject-days.
            $byVariant[(string) $row['variant']][(string) $row['subject_hash']] = true;
        }

        return $byVariant;
    }

    /**
     * Remove subjects present in more than one arm.
     *
     * @param  array<string,array<string,true>> $byVariant
     * @return array{0: array<string,array<string,true>>, 1: int} cleaned arms, and how many
     *                                                            distinct subjects were dropped
     */
    private function dropCrossArmSubjects(array $byVariant): array
    {
        if (count($byVariant) < 2) {
            return [$byVariant, 0];
        }

        $seenIn = [];
        foreach ($byVariant as $subjects) {
            foreach ($subjects as $hash => $_) {
                $seenIn[$hash] = ($seenIn[$hash] ?? 0) + 1;
            }
        }

        $contaminated = array_filter($seenIn, static fn (int $arms): bool => $arms > 1);

        if ($contaminated === []) {
            return [$byVariant, 0];
        }

        foreach ($byVariant as $variant => $subjects) {
            $byVariant[$variant] = array_diff_key($subjects, $contaminated);
        }

        return [$byVariant, count($contaminated)];
    }

    /**
     * The pseudonymised identities that converted, as a set for O(1) intersection.
     *
     * Hashed here, with the framework's pepper, so the application never sees it — see
     * {@see OutcomeSourceInterface}.
     *
     * @return array<string,true>
     */
    private function convertedHashes(string $metric, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $hashes = [];
        foreach ($this->outcomes->convertedSubjects($metric, $from, $to) as $subjectId) {
            $hashes[$this->pseudonymiser->pseudonymise($subjectId)] = true;
        }

        return $hashes;
    }

    /**
     * Refuse a window the raw ledger cannot fully cover, and warn about one that is still open.
     *
     * @return list<string>
     */
    private function windowWarnings(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($from > $to) {
            throw new \InvalidArgumentException('The readout window starts after it ends.');
        }

        $earliest = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify(sprintf('-%d days', $this->ledgerRetentionDays));

        if ($from < $earliest) {
            throw new \InvalidArgumentException(sprintf(
                'The readout window starts %s, but the raw exposure ledger only retains %d days '
                . '(back to %s). An experiment must finish inside the retention window: reading '
                . 'a partially-pruned window would drop the earliest subjects, who are not a '
                . 'random subset of the population.',
                $from->format('Y-m-d'),
                $this->ledgerRetentionDays,
                $earliest->format('Y-m-d'),
            ));
        }

        $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');

        if ($to->format('Y-m-d') >= $today) {
            return ['The window includes today, which is still accumulating. Treat this as a '
                . 'progress check, not a result: peeking at a running experiment and stopping '
                . 'when it looks significant inflates the false-positive rate well beyond 5%.'];
        }

        return [];
    }

    /** @param list<VariantResult> $variants */
    private function arm(array $variants, string $variant): ?VariantResult
    {
        foreach ($variants as $result) {
            if ($result->variant === $variant) {
                return $result;
            }
        }

        return null;
    }

    /**
     * The arm to compare against the control: the largest one that is not the control.
     *
     * A multivariate flag has several, and picking the largest is the only defensible
     * automatic choice — picking the *best* would be selecting the winner after seeing the
     * data, which is how a platform manufactures significance that is not there.
     *
     * @param list<VariantResult> $variants
     */
    private function treatmentArm(array $variants, string $control): ?VariantResult
    {
        foreach ($variants as $result) {
            if ($result->variant !== $control) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param list<VariantResult> $variants
     * @param list<string>        $warnings
     */
    private function compare(
        string $flag,
        string $metric,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $variants,
        ?VariantResult $control,
        ?VariantResult $treatment,
        array $warnings,
    ): ReadoutResult {
        if ($control === null || $treatment === null) {
            $warnings[] = $control === null
                ? 'No control arm was recorded in this window, so nothing can be compared. A '
                  . 'flag that only ever reports exposures when it is ON has no control group.'
                : 'Only one arm was recorded in this window, so nothing can be compared.';

            return new ReadoutResult(
                $flag, $metric, $from->format('Y-m-d'), $to->format('Y-m-d'),
                $variants, $control, $treatment, null, null, false, $warnings,
            );
        }

        $reliable = Statistics::isReliable(
            $control->conversions, $control->subjects,
            $treatment->conversions, $treatment->subjects,
        );

        if (!$reliable) {
            $warnings[] = sprintf(
                'Sample too small for a valid test: the normal approximation needs at least %d '
                . 'expected conversions and %d expected non-conversions in each arm. Any p-value '
                . 'computed here would look precise and mean nothing.',
                Statistics::MIN_EXPECTED_PER_CELL,
                Statistics::MIN_EXPECTED_PER_CELL,
            );
        }

        return new ReadoutResult(
            flag:      $flag,
            metric:    $metric,
            from:      $from->format('Y-m-d'),
            to:        $to->format('Y-m-d'),
            variants:  $variants,
            control:   $control,
            treatment: $treatment,
            pValue:    Statistics::twoProportionPValue(
                $control->conversions, $control->subjects,
                $treatment->conversions, $treatment->subjects,
            ),
            interval:  Statistics::differenceInterval(
                $control->conversions, $control->subjects,
                $treatment->conversions, $treatment->subjects,
            ),
            reliable:  $reliable,
            warnings:  $warnings,
        );
    }
}
