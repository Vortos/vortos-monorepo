<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\ObjectStore\Command\ObjectStoreLifecycleCommand;
use Vortos\ObjectStore\Contract\LifecycleManagerInterface;
use Vortos\ObjectStore\Lifecycle\LifecycleConfiguration;
use Vortos\ObjectStore\Lifecycle\LifecyclePlan;
use Vortos\ObjectStore\Lifecycle\LifecyclePlanChange;
use Vortos\ObjectStore\Lifecycle\LifecycleRule;

final class ObjectStoreLifecycleCommandTest extends TestCase
{
    public function test_plan_returns_zero_when_no_change_is_needed(): void
    {
        $configuration = new LifecycleConfiguration([LifecycleRule::temporaryUploadExpiry('managed', 'tmp', 86400)->toS3Rule()]);
        $tester = new CommandTester(new ObjectStoreLifecycleCommand(new FakeLifecycleManager(
            new LifecyclePlan($configuration, $configuration, LifecyclePlanChange::None, 'managed'),
        )));

        $exitCode = $tester->execute(['action' => 'plan']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('already up to date', $tester->getDisplay());
    }

    public function test_plan_returns_two_when_change_is_needed(): void
    {
        $tester = new CommandTester(new ObjectStoreLifecycleCommand(new FakeLifecycleManager($this->createPlan())));

        $this->assertSame(2, $tester->execute(['action' => 'plan']));
    }

    public function test_apply_requires_confirmation(): void
    {
        $tester = new CommandTester(new ObjectStoreLifecycleCommand(new FakeLifecycleManager($this->createPlan())));

        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'apply']));
        $this->assertStringContainsString('requires --confirm', $tester->getDisplay());
    }

    public function test_apply_dry_run_does_not_write(): void
    {
        $manager = new FakeLifecycleManager($this->createPlan());
        $tester = new CommandTester(new ObjectStoreLifecycleCommand($manager));

        $this->assertSame(2, $tester->execute(['action' => 'apply', '--dry-run' => true]));
        $this->assertFalse($manager->applied);
    }

    public function test_remove_with_confirmation_applies_remove_plan(): void
    {
        $manager = new FakeLifecycleManager($this->createPlan(), $this->removePlan());
        $tester = new CommandTester(new ObjectStoreLifecycleCommand($manager));

        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'remove', '--confirm' => true]));
        $this->assertTrue($manager->applied);
    }

    public function test_json_plan_outputs_machine_readable_payload(): void
    {
        $tester = new CommandTester(new ObjectStoreLifecycleCommand(new FakeLifecycleManager($this->createPlan())));

        $tester->execute(['action' => 'plan', '--json' => true]);

        $this->assertJson($tester->getDisplay());
    }

    private function createPlan(): LifecyclePlan
    {
        $current = LifecycleConfiguration::empty();
        $desired = $current->withRule(LifecycleRule::temporaryUploadExpiry('managed', 'tmp', 86400));

        return new LifecyclePlan($current, $desired, LifecyclePlanChange::Create, 'managed');
    }

    private function removePlan(): LifecyclePlan
    {
        $current = (new LifecycleConfiguration())->withRule(LifecycleRule::temporaryUploadExpiry('managed', 'tmp', 86400));
        $desired = $current->withoutRule('managed');

        return new LifecyclePlan($current, $desired, LifecyclePlanChange::Remove, 'managed');
    }
}

final class FakeLifecycleManager implements LifecycleManagerInterface
{
    public bool $applied = false;

    public function __construct(
        private readonly LifecyclePlan $plan,
        private readonly ?LifecyclePlan $removePlan = null,
    ) {}

    public function current(): LifecycleConfiguration
    {
        return $this->plan->current();
    }

    public function planTemporaryUploadExpiry(): LifecyclePlan
    {
        return $this->plan;
    }

    public function planRemoveManagedRule(): LifecyclePlan
    {
        return $this->removePlan ?? $this->plan;
    }

    public function apply(LifecyclePlan $plan): LifecycleConfiguration
    {
        $this->applied = true;
        return $plan->desired();
    }
}
