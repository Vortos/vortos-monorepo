<?php

declare(strict_types=1);

namespace Vortos\Backup\Runtime;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A small, dependency-free evaluator for standard 5-field cron expressions
 * (`minute hour day-of-month month day-of-week`), supporting `*`, lists (`,`), ranges (`-`) and
 * steps (`*​/n`, `a-b/n`). Day-of-week accepts 0 or 7 for Sunday.
 *
 * It is pure (no I/O, no "now()") so the backup worker's scheduling is fully deterministic and
 * testable: {@see isDue()} answers "does this minute match?" and {@see nextDueAfter()} finds the next
 * matching minute strictly after a given instant. When both day-of-month and day-of-week are
 * restricted, cron's historical OR semantics apply (a match on either day field fires).
 */
final class CronDueEvaluator
{
    /** Hard bound so a malformed-but-valid-looking expression can never loop unbounded. */
    private const MAX_LOOKAHEAD_MINUTES = 366 * 24 * 60;

    public function isDue(string $cron, DateTimeImmutable $at): bool
    {
        [$min, $hour, $dom, $mon, $dow] = $this->fields($cron);

        $minuteOk = $this->matches($min, 0, 59, (int) $at->format('i'));
        $hourOk = $this->matches($hour, 0, 23, (int) $at->format('G'));
        $monthOk = $this->matches($mon, 1, 12, (int) $at->format('n'));

        if (!$minuteOk || !$hourOk || !$monthOk) {
            return false;
        }

        return $this->dayMatches($dom, $dow, $at);
    }

    /**
     * The next instant strictly after $after (truncated to whole minutes) at which $cron fires.
     *
     * @throws InvalidArgumentException when no match exists within the lookahead bound.
     */
    public function nextDueAfter(string $cron, DateTimeImmutable $after): DateTimeImmutable
    {
        // Advance to the start of the next whole minute so "strictly after" holds.
        $candidate = $after->setTime(
            (int) $after->format('G'),
            (int) $after->format('i'),
            0,
        )->modify('+1 minute');

        for ($i = 0; $i < self::MAX_LOOKAHEAD_MINUTES; $i++) {
            if ($this->isDue($cron, $candidate)) {
                return $candidate;
            }
            $candidate = $candidate->modify('+1 minute');
        }

        throw new InvalidArgumentException(sprintf('Cron "%s" has no next occurrence within a year.', $cron));
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    private function fields(string $cron): array
    {
        $fields = preg_split('/\s+/', trim($cron)) ?: [];
        if (count($fields) !== 5) {
            throw new InvalidArgumentException(sprintf('Cron must have exactly 5 fields, got "%s".', $cron));
        }

        /** @var array{0: string, 1: string, 2: string, 3: string, 4: string} $fields */
        return $fields;
    }

    private function dayMatches(string $dom, string $dow, DateTimeImmutable $at): bool
    {
        $domRestricted = $dom !== '*';
        $dowRestricted = $dow !== '*';

        $domOk = $this->matches($dom, 1, 31, (int) $at->format('j'));
        // Normalise Sunday: cron allows 0 or 7; format('w') gives 0..6 (Sun..Sat).
        $weekday = (int) $at->format('w');
        $dowOk = $this->matches($this->normaliseDow($dow), 0, 6, $weekday);

        if ($domRestricted && $dowRestricted) {
            return $domOk || $dowOk; // classic cron OR
        }
        if ($domRestricted) {
            return $domOk;
        }
        if ($dowRestricted) {
            return $dowOk;
        }

        return true;
    }

    private function normaliseDow(string $field): string
    {
        // Map any "7" token to "0" so both spell Sunday.
        return (string) preg_replace_callback('/\d+/', static function (array $m): string {
            return $m[0] === '7' ? '0' : $m[0];
        }, $field);
    }

    private function matches(string $field, int $min, int $max, int $value): bool
    {
        foreach (explode(',', $field) as $part) {
            if ($this->partMatches(trim($part), $min, $max, $value)) {
                return true;
            }
        }

        return false;
    }

    private function partMatches(string $part, int $min, int $max, int $value): bool
    {
        $step = 1;
        if (str_contains($part, '/')) {
            [$range, $stepStr] = explode('/', $part, 2);
            $step = (int) $stepStr;
            if ($step < 1) {
                throw new InvalidArgumentException(sprintf('Invalid cron step "/%s".', $stepStr));
            }
        } else {
            $range = $part;
        }

        if ($range === '*') {
            $lo = $min;
            $hi = $max;
        } elseif (str_contains($range, '-')) {
            [$loStr, $hiStr] = explode('-', $range, 2);
            $lo = (int) $loStr;
            $hi = (int) $hiStr;
        } else {
            $lo = $hi = (int) $range;
        }

        if ($lo < $min || $hi > $max || $lo > $hi) {
            throw new InvalidArgumentException(sprintf('Cron field value out of range: "%s".', $part));
        }

        if ($value < $lo || $value > $hi) {
            return false;
        }

        return (($value - $lo) % $step) === 0;
    }
}
