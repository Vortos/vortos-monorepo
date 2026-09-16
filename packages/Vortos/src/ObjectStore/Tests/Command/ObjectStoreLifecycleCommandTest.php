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
use Vortos\ObjectStore\Lifecycle\LifecycleRuleChange;
use Vortos\ObjectStore\Lifecycle\ObjectStorageClass;

final class ObjectStoreLifecycleCommandTest extends TestCase
{
    public function test_plan_returns_zero_when_no_change_is_needed(): void
    {
        $rule = LifecycleRule::temporaryUploadExpiry('vortos-tmp', 'tmp', 86400)->toS3Rule();
        $configuration = new LifecycleConfiguration([$rule]);
        $tester = new CommandTester(new ObjectStoreLifecycleCommand(new FakeLifecycleManager(
            new LifecyclePlan($configuration, $configuration, [
                new LifecycleRuleChange('vortos-tmp', LifecyclePlanChange::None, $rule, $rule),
            ]),
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

    public function test_plan_table_shows_each_rule_change_with_its_transitions(): void
    {
        $tester = new CommandTester(new ObjectStoreLifecycleCommand(new FakeLifecycleManager($this->transitionPlan())));

        $tester->execute(['action' => 'plan']);
        $display = $tester->getDisplay();

        $this->assertStringContainsString('Transitions', $display);
        $this->assertStringContainsString('create', $display);
        $this->assertStringContainsString('vortos-submissions-ia', $display);
        $this->assertStringContainsString('submissions/', $display);
        $this->assertStringContainsString('90d → STANDARD_IA', $display);
    }

    public function test_apply_requires_confirmation(): void
    {
        $manager = new FakeLifecycleManager($this->createPlan());
        $tester = new CommandTester(new ObjectStoreLifecycleCommand($manager));

        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'apply']));
        $this->assertStringContainsString('requires --confirm', $tester->getDisplay());
        $this->assertFalse($manager->applied);
    }

    public function test_apply_dry_run_does_not_write(): void
    {
        $manager = new FakeLifecycleManager($this->createPlan());
        $tester = new CommandTester(new ObjectStoreLifecycleCommand($manager));

        $this->assertSame(2, $tester->execute(['action' => 'apply', '--dry-run' => true]));
        $this->assertFalse($manager->applied);
    }

    public function test_apply_with_confirmation_applies_managed_rules_plan(): void
    {
        $plan = $this->createPlan();
        $manager = new FakeLifecycleManager($plan, $this->removePlan());
        $tester = new CommandTester(new ObjectStoreLifecycleCommand($manager));

        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'apply', '--confirm' => true]));
        $this->assertSame($plan, $manager->appliedPlan);
    }

    public function test_remove_with_confirmation_applies_remove_plan(): void
    {
        $removePlan = $this->removePlan();
        $manager = new FakeLifecycleManager($this->createPlan(), $removePlan);
        $tester = new CommandTester(new ObjectStoreLifecycleCommand($manager));

        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'remove', '--confirm' => true]));
        $this->assertSame($removePlan, $manager->appliedPlan);
    }

    public function test_json_plan_outputs_machine_readable_payload(): void
    {
        $tester = new CommandTester(new ObjectStoreLifecycleCommand(new FakeLifecycleManager($this->createPlan())));

        $tester->execute(['action' => 'plan', '--json' => true]);

        $this->assertJson($tester->getDisplay());
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['plan']['has_changes']);
        $this->assertSame('vortos-tmp', $payload['plan']['changes'][0]['rule_id']);
        $this->assertSame(LifecyclePlanChange::Create->value, $payload['plan']['changes'][0]['change']);
    }

    public function test_json_apply_lists_only_rules_that_changed(): void
    {
        $unchanged = LifecycleRule::temporaryUploadExpiry('vortos-tmp', 'tmp', 86400)->toS3Rule();
        $created = LifecycleRule::transitionAfter('vortos-submissions-ia', 'submissions', 90, ObjectStorageClass::InfrequentAccess)->toS3Rule();
        $plan = new LifecyclePlan(
            new LifecycleConfiguration([$unchanged]),
            new LifecycleConfiguration([$unchanged, $created]),
            [
                new LifecycleRuleChange('vortos-tmp', LifecyclePlanChange::None, $unchanged, $unchanged),
                new LifecycleRuleChange('vortos-submissions-ia', LifecyclePlanChange::Create, null, $created),
            ],
        );
        $tester = new CommandTester(new ObjectStoreLifecycleCommand(new FakeLifecycleManager($plan)));

        $tester->execute(['action' => 'apply', '--confirm' => true, '--json' => true]);

        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['applied']);
        $this->assertSame(['vortos-submissions-ia'], array_column($payload['changes'], 'rule_id'));
        $this->assertCount(2, $payload['rules']);
    }

    private function createPlan(): LifecyclePlan
    {
        $rule = LifecycleRule::temporaryUploadExpiry('vortos-tmp', 'tmp', 86400);
        $current = LifecycleConfiguration::empty();

        return new LifecyclePlan($current, $current->withRule($rule), [
            new LifecycleRuleChange('vortos-tmp', LifecyclePlanChange::Create, null, $rule->toS3Rule()),
        ]);
    }

    private function transitionPlan(): LifecyclePlan
    {
        $rule = LifecycleRule::transitionAfter('vortos-submissions-ia', 'submissions', 90, ObjectStorageClass::InfrequentAccess);
        $current = LifecycleConfiguration::empty();

        return new LifecyclePlan($current, $current->withRule($rule), [
            new LifecycleRuleChange('vortos-submissions-ia', LifecyclePlanChange::Create, null, $rule->toS3Rule()),
        ]);
    }

    private function removePlan(): LifecyclePlan
    {
        $rule = LifecycleRule::temporaryUploadExpiry('vortos-tmp', 'tmp', 86400);
        $current = LifecycleConfiguration::empty()->withRule($rule);

        return new LifecyclePlan($current, $current->withoutRule('vortos-tmp'), [
            new LifecycleRuleChange('vortos-tmp', LifecyclePlanChange::Remove, $rule->toS3Rule(), null),
        ]);
    }
}

final class FakeLifecycleManager implements LifecycleManagerInterface
{
    public bool $applied = false;

    public ?LifecyclePlan $appliedPlan = null;

    public function __construct(
        private readonly LifecyclePlan $plan,
        private readonly ?LifecyclePlan $removePlan = null,
    ) {}

    public function current(): LifecycleConfiguration
    {
        return $this->plan->current();
    }

    public function planManagedRules(): LifecyclePlan
    {
        return $this->plan;
    }

    public function planRemoveManagedRules(): LifecyclePlan
    {
        return $this->removePlan ?? $this->plan;
    }

    public function apply(LifecyclePlan $plan): LifecycleConfiguration
    {
        $this->applied = true;
        $this->appliedPlan = $plan;

        return $plan->desired();
    }
}
