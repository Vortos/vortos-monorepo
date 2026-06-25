<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Preflight\DeployDoctor;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;

/**
 * §15.2 mandatory: the doctor has no code path that returns a clear report when a
 * check could not complete. Asserted behaviourally (a throwing check yields a Fail,
 * regardless of message/type) and structurally (the aggregator catches Throwable and
 * the catch produces a Fail finding).
 */
final class DoctorFailClosedArchTest extends TestCase
{
    use PreflightTestFactory;

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function throwables(): iterable
    {
        yield 'runtime' => [new \RuntimeException('boom')];
        yield 'logic' => [new \LogicException('bad')];
        yield 'error' => [new \TypeError('type')];
        yield 'empty message' => [new \RuntimeException('')];
    }

    #[DataProvider('throwables')]
    public function test_any_throwable_becomes_fail(\Throwable $t): void
    {
        $doctor = new DeployDoctor([$this->throwingCheck($t)]);

        $report = $doctor->run($this->context());

        $this->assertFalse($report->isClear());
        $this->assertSame(1, $report->exitCode());
        $this->assertCount(1, $report->failures());
    }

    public function test_aggregator_source_catches_throwable_and_fails(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(DeployDoctor::class))->getFileName());

        $this->assertStringContainsString('catch (\Throwable', $source, 'doctor must catch every throwable');
        $this->assertStringContainsString('PreflightFinding::fail', $source, 'the catch must produce a Fail finding');
        $this->assertStringNotContainsString('PreflightFinding::pass', $source, 'doctor must never synthesise a Pass');
    }

    private function throwingCheck(\Throwable $t): PreflightCheckInterface
    {
        return new class($t) implements PreflightCheckInterface {
            public function __construct(private readonly \Throwable $t) {}

            public function id(): string
            {
                return 'arch.throwing';
            }

            public function category(): PreflightCategory
            {
                return PreflightCategory::Plan;
            }

            public function check(PreflightContext $context): PreflightFinding
            {
                throw $this->t;
            }
        };
    }
}
