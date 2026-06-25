<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Model\CommandStep;
use Vortos\Pipeline\Model\Matrix;
use Vortos\Pipeline\Model\Permission;
use Vortos\Pipeline\Model\PermissionAccess;
use Vortos\Pipeline\Model\PermissionScope;
use Vortos\Pipeline\Model\Permissions;
use Vortos\Pipeline\Model\RunnerSpec;
use Vortos\Pipeline\Model\Stage;
use Vortos\Pipeline\Model\StageKind;

final class StageTest extends TestCase
{
    public function test_construction(): void
    {
        $stage = new Stage(
            id: 'tests',
            displayName: 'Tests',
            kind: StageKind::Test,
            steps: [new CommandStep('Run', 'phpunit')],
        );

        $this->assertSame('tests', $stage->id);
        $this->assertSame('Tests', $stage->displayName);
        $this->assertSame(StageKind::Test, $stage->kind);
        $this->assertCount(1, $stage->steps);
    }

    public function test_empty_id_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Stage(id: '', displayName: 'X', kind: StageKind::Test, steps: [new CommandStep('Run', 'phpunit')]);
    }

    public function test_empty_steps_without_matrix_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Stage(id: 'x', displayName: 'X', kind: StageKind::Test, steps: []);
    }

    public function test_empty_steps_with_matrix_accepted(): void
    {
        $stage = new Stage(
            id: 'split',
            displayName: 'Split',
            kind: StageKind::Split,
            steps: [],
            matrix: new Matrix('package', [['local_path' => 'a', 'split_repository' => 'b']]),
        );

        $this->assertSame('split', $stage->id);
    }

    public function test_to_array_full(): void
    {
        $stage = new Stage(
            id: 'deploy',
            displayName: 'Deploy',
            kind: StageKind::Deploy,
            steps: [new CommandStep('Deploy', 'vortos deploy')],
            needs: ['tests'],
            condition: "github.ref == 'refs/heads/main'",
            runner: new RunnerSpec('ubuntu-latest'),
            permissions: new Permissions([
                new Permission(PermissionScope::Contents, PermissionAccess::Read),
            ]),
            environment: 'production',
            timeoutMinutes: 30,
        );

        $array = $stage->toArray();

        $this->assertSame('deploy', $array['id']);
        $this->assertSame(['tests'], $array['needs']);
        $this->assertSame("github.ref == 'refs/heads/main'", $array['condition']);
        $this->assertSame('production', $array['environment']);
        $this->assertSame(30, $array['timeout_minutes']);
        $this->assertArrayHasKey('permissions', $array);
    }

    public function test_to_array_minimal(): void
    {
        $stage = new Stage(
            id: 'tests',
            displayName: 'Tests',
            kind: StageKind::Test,
            steps: [new CommandStep('Run', 'phpunit')],
        );

        $array = $stage->toArray();

        $this->assertSame('tests', $array['id']);
        $this->assertArrayNotHasKey('needs', $array);
        $this->assertArrayNotHasKey('condition', $array);
        $this->assertArrayNotHasKey('environment', $array);
        $this->assertArrayNotHasKey('timeout_minutes', $array);
    }
}
