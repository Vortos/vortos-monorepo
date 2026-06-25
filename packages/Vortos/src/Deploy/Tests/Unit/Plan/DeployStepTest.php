<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Plan;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Plan\DeployStep;
use Vortos\Deploy\Plan\StepAction;
use Vortos\Secrets\Preflight\SecretReference;
use Vortos\Secrets\Value\SecretKey;

final class DeployStepTest extends TestCase
{
    public function test_basic_step_to_array(): void
    {
        $step = new DeployStep(StepAction::RunMigrations, 'Run migrations');
        $arr = $step->toArray();

        self::assertSame('run-migrations', $arr['action']);
        self::assertSame('Run migrations', $arr['description']);
        self::assertArrayNotHasKey('params', $arr);
        self::assertArrayNotHasKey('secret_references', $arr);
    }

    public function test_step_with_params_sorted(): void
    {
        $step = new DeployStep(StepAction::StartContainer, 'Start', ['z_param' => 'z', 'a_param' => 'a']);
        $arr = $step->toArray();

        $paramKeys = array_keys($arr['params']);
        self::assertSame(['a_param', 'z_param'], $paramKeys);
    }

    public function test_step_with_secret_references(): void
    {
        $step = new DeployStep(
            StepAction::StartContainer,
            'Start',
            [],
            [new SecretReference(SecretKey::fromString('db_pass'))],
        );
        $arr = $step->toArray();

        self::assertSame(['db_pass'], $arr['secret_references']);
    }
}
