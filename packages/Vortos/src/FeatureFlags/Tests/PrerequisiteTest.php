<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagEvaluator;
use Vortos\FeatureFlags\FlagResolverInterface;
use Vortos\FeatureFlags\FlagValue;
use Vortos\FeatureFlags\FlagValueType;
use Vortos\FeatureFlags\Prerequisite;

final class PrerequisiteTest extends TestCase
{
    public function test_dependent_flag_off_when_prerequisite_off(): void
    {
        $base      = $this->flag('base', enabled: false);
        $evaluator = $this->evaluatorWith([$base]);
        $dependent = $this->flag('feature', prerequisites: [Prerequisite::on('base')]);

        $this->assertFalse($evaluator->evaluate($dependent, new FlagContext('u')));
    }

    public function test_dependent_flag_on_when_prerequisite_on(): void
    {
        $base      = $this->flag('base', enabled: true);
        $evaluator = $this->evaluatorWith([$base]);
        $dependent = $this->flag('feature', prerequisites: [Prerequisite::on('base')]);

        $this->assertTrue($evaluator->evaluate($dependent, new FlagContext('u')));
    }

    public function test_missing_prerequisite_is_unmet(): void
    {
        $evaluator = $this->evaluatorWith([]); // base not registered
        $dependent = $this->flag('feature', prerequisites: [Prerequisite::on('base')]);

        $this->assertFalse($evaluator->evaluate($dependent, new FlagContext('u')));
    }

    public function test_typed_prerequisite_value_must_match(): void
    {
        $colour = new FeatureFlag(
            'id-colour', 'theme', '', true, [], null,
            new \DateTimeImmutable(), new \DateTimeImmutable(),
            valueType: FlagValueType::String,
            defaultValue: FlagValue::string('dark'),
        );
        $evaluator = $this->evaluatorWith([$colour]);

        $wantsDark  = $this->flag('dark-only', prerequisites: [new Prerequisite('theme', FlagValue::string('dark'))]);
        $wantsLight = $this->flag('light-only', prerequisites: [new Prerequisite('theme', FlagValue::string('light'))]);

        $this->assertTrue($evaluator->evaluate($wantsDark, new FlagContext('u')));
        $this->assertFalse($evaluator->evaluate($wantsLight, new FlagContext('u')));
    }

    public function test_prerequisite_chain(): void
    {
        // c requires b, b requires a; a is on.
        $a = $this->flag('a', enabled: true);
        $b = $this->flag('b', enabled: true, prerequisites: [Prerequisite::on('a')]);
        $evaluator = $this->evaluatorWith([$a, $b]);
        $c = $this->flag('c', enabled: true, prerequisites: [Prerequisite::on('b')]);

        $this->assertTrue($evaluator->evaluate($c, new FlagContext('u')));

        // Turn a off → whole chain collapses.
        $aOff = $this->flag('a', enabled: false);
        $evaluatorOff = $this->evaluatorWith([$aOff, $b]);
        $this->assertFalse($evaluatorOff->evaluate($c, new FlagContext('u')));
    }

    /** @param FeatureFlag[] $flags */
    private function evaluatorWith(array $flags): FlagEvaluator
    {
        $map = [];
        foreach ($flags as $f) {
            $map[$f->name] = $f;
        }

        $resolver = new class($map) implements FlagResolverInterface {
            /** @param array<string,FeatureFlag> $map */
            public function __construct(private readonly array $map) {}

            public function resolve(string $name): ?FeatureFlag
            {
                return $this->map[$name] ?? null;
            }
        };

        return new FlagEvaluator(flags: $resolver);
    }

    /** @param Prerequisite[] $prerequisites */
    private function flag(string $name, bool $enabled = true, array $prerequisites = []): FeatureFlag
    {
        $now = new \DateTimeImmutable();
        return new FeatureFlag('id-' . $name, $name, '', $enabled, [], null, $now, $now, prerequisites: $prerequisites);
    }
}
