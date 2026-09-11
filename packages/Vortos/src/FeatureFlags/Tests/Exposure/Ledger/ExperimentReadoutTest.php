<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Exposure\Ledger;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Exposure\Ledger\SubjectPseudonymiser;
use Vortos\FeatureFlags\Exposure\Readout\ExperimentReadout;
use Vortos\FeatureFlags\Exposure\Readout\OutcomeSourceInterface;

/**
 * The join between "who saw what" and "who converted", and the guards that stop it producing
 * a confident answer it has not earned.
 */
final class ExperimentReadoutTest extends TestCase
{
    private const PEPPER = 'e6f1c0aa8b2d4f7391c5ae0b7d2f48366a9c1e5b0d8342f7ac916be4d05f2371';

    public function test_it_counts_subjects_and_conversions_per_arm(): void
    {
        $readout = $this->readout(
            exposures: [
                ['variant' => 'false', 'subject' => 'u1'],
                ['variant' => 'false', 'subject' => 'u2'],
                ['variant' => 'true',  'subject' => 'u3'],
                ['variant' => 'true',  'subject' => 'u4'],
            ],
            converted: ['u2', 'u3', 'u4'],
        );

        $result = $readout->readout('checkout', 'signup', $this->from(), $this->to());

        self::assertSame(2, $result->control?->subjects);
        self::assertSame(1, $result->control?->conversions);
        self::assertSame(2, $result->treatment?->subjects);
        self::assertSame(2, $result->treatment?->conversions);
    }

    public function test_a_subject_exposed_on_many_days_counts_once(): void
    {
        // An arm counts subjects, not subject-days. Counting rows instead would inflate every
        // denominator by however long the experiment ran.
        $readout = $this->readout(
            exposures: [
                ['variant' => 'true', 'subject' => 'u1'],
                ['variant' => 'true', 'subject' => 'u1'],
                ['variant' => 'true', 'subject' => 'u1'],
            ],
            converted: ['u1'],
        );

        $result = $readout->readout('checkout', 'signup', $this->from(), $this->to());

        self::assertSame(1, $result->treatment?->subjects);
        self::assertSame(1, $result->treatment?->conversions);
    }

    public function test_a_subject_in_both_arms_is_excluded_from_both(): void
    {
        // The flag did not hold still. Counting them in both arms inflates both denominators;
        // picking one biases whichever they land in. Neither is defensible, so they leave.
        $readout = $this->readout(
            exposures: [
                ['variant' => 'false', 'subject' => 'u1'],
                ['variant' => 'true',  'subject' => 'u1'],
                ['variant' => 'false', 'subject' => 'u2'],
                ['variant' => 'true',  'subject' => 'u3'],
            ],
            converted: ['u1', 'u2'],
        );

        $result = $readout->readout('checkout', 'signup', $this->from(), $this->to());

        self::assertSame(1, $result->control?->subjects);
        self::assertSame(1, $result->treatment?->subjects);
    }

    public function test_cross_arm_contamination_is_surfaced_as_a_warning(): void
    {
        $readout = $this->readout(
            exposures: [
                ['variant' => 'false', 'subject' => 'u1'],
                ['variant' => 'true',  'subject' => 'u1'],
            ],
            converted: [],
        );

        $result = $readout->readout('checkout', 'signup', $this->from(), $this->to());

        // Silently dropping them would hide that the experiment was disturbed, which is
        // itself the finding.
        self::assertNotEmpty(array_filter(
            $result->warnings,
            static fn (string $w): bool => str_contains($w, 'more than one variant'),
        ));
    }

    public function test_a_conversion_by_an_unexposed_subject_is_ignored(): void
    {
        // Someone converted who never saw the flag. They are not in the experiment, and
        // counting them would attribute an outcome to an exposure that never happened.
        $readout = $this->readout(
            exposures: [['variant' => 'true', 'subject' => 'u1']],
            converted: ['u1', 'stranger'],
        );

        $result = $readout->readout('checkout', 'signup', $this->from(), $this->to());

        self::assertSame(1, $result->treatment?->conversions);
    }

    public function test_a_small_sample_is_not_significant_however_the_p_value_falls(): void
    {
        $readout = $this->readout(
            exposures: [
                ['variant' => 'false', 'subject' => 'u1'],
                ['variant' => 'true',  'subject' => 'u2'],
            ],
            converted: ['u2'],
        );

        $result = $readout->readout('checkout', 'signup', $this->from(), $this->to());

        self::assertFalse($result->reliable);
        self::assertFalse($result->isSignificant());
    }

    public function test_a_window_older_than_ledger_retention_is_refused(): void
    {
        // Not truncated silently. A partially-pruned window drops the earliest subjects, who
        // are systematically different from the latest — a readout that is wrong rather than
        // merely incomplete.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/only retains 30 days/');

        $this->readout()->readout(
            'checkout',
            'signup',
            new DateTimeImmutable('-200 days', new DateTimeZone('UTC')),
            new DateTimeImmutable('-190 days', new DateTimeZone('UTC')),
        );
    }

    public function test_a_missing_outcome_source_names_the_interface_to_implement(): void
    {
        // The framework cannot know whether an application has bound one, so this must be an
        // actionable message rather than a TypeError naming a constructor nobody wrote.
        $readout = new ExperimentReadout(
            $this->createMock(\Doctrine\DBAL\Connection::class),
            new SubjectPseudonymiser(self::PEPPER),
            null,
            'vortos_feature_flag_exposures',
            30,
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/OutcomeSourceInterface is bound/');

        $readout->readout('checkout', 'signup', $this->from(), $this->to());
    }

    public function test_an_inverted_window_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->readout()->readout('checkout', 'signup', $this->to(), $this->from());
    }

    public function test_a_window_including_today_warns_about_peeking(): void
    {
        $result = $this->readout()->readout(
            'checkout',
            'signup',
            new DateTimeImmutable('-3 days', new DateTimeZone('UTC')),
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        self::assertNotEmpty(array_filter(
            $result->warnings,
            static fn (string $w): bool => str_contains($w, 'still accumulating'),
        ));
    }

    public function test_a_single_arm_cannot_be_compared(): void
    {
        $readout = $this->readout(
            exposures: [['variant' => 'true', 'subject' => 'u1']],
            converted: ['u1'],
        );

        $result = $readout->readout('checkout', 'signup', $this->from(), $this->to());

        self::assertNull($result->pValue);
        self::assertFalse($result->isSignificant());
        self::assertNotEmpty($result->warnings);
    }

    public function test_relative_lift_is_computed_against_the_control(): void
    {
        $exposures = [];
        for ($i = 0; $i < 100; $i++) {
            $exposures[] = ['variant' => 'false', 'subject' => 'c' . $i];
            $exposures[] = ['variant' => 'true',  'subject' => 't' . $i];
        }

        $converted = [];
        for ($i = 0; $i < 10; $i++) {
            $converted[] = 'c' . $i;
        }
        for ($i = 0; $i < 20; $i++) {
            $converted[] = 't' . $i;
        }

        $result = $this->readout($exposures, $converted)
            ->readout('checkout', 'signup', $this->from(), $this->to());

        // 10% against 20% is a doubling: +100% relative.
        self::assertEqualsWithDelta(1.0, (float) $result->relativeLift(), 1e-9);
    }

    /**
     * @param list<array{variant:string,subject:string}> $exposures
     * @param list<string>                               $converted
     */
    private function readout(array $exposures = [], array $converted = []): ExperimentReadout
    {
        $pseudonymiser = new SubjectPseudonymiser(self::PEPPER);

        $rows = array_map(
            static fn (array $e): array => [
                'variant'      => $e['variant'],
                'subject_hash' => $pseudonymiser->pseudonymise($e['subject']),
            ],
            $exposures,
        );

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($rows);

        return new ExperimentReadout(
            $connection,
            $pseudonymiser,
            new class ($converted) implements OutcomeSourceInterface {
                /** @param list<string> $converted */
                public function __construct(private readonly array $converted) {}

                public function convertedSubjects(string $metric, DateTimeImmutable $from, DateTimeImmutable $to): iterable
                {
                    return $this->converted;
                }

                public function availableMetrics(): array
                {
                    return ['signup'];
                }
            },
            'vortos_feature_flag_exposures',
            30,
        );
    }

    private function from(): DateTimeImmutable
    {
        return new DateTimeImmutable('-7 days', new DateTimeZone('UTC'));
    }

    private function to(): DateTimeImmutable
    {
        return new DateTimeImmutable('-1 day', new DateTimeZone('UTC'));
    }
}
