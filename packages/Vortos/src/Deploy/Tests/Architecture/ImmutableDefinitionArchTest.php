<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ImmutableDefinitionArchTest extends TestCase
{
    public function test_definition_and_plan_classes_are_final_readonly(): void
    {
        $root = dirname(__DIR__, 2);
        $dirs = ['Definition', 'Plan'];
        $violations = [];

        foreach ($dirs as $dirName) {
            $dir = $root . '/' . $dirName;
            if (!is_dir($dir)) {
                continue;
            }

            foreach (glob($dir . '/*.php') as $file) {
                $code = (string) file_get_contents($file);
                $basename = basename($file, '.php');

                if (str_contains($code, 'interface ') || str_contains($code, 'enum ') || str_contains($code, 'abstract class')) {
                    continue;
                }

                if (!str_contains($code, 'class ')) {
                    continue;
                }

                if ($basename === 'DeploymentDefinitionBuilder' || $basename === 'LayeredDefinitionResolver' || $basename === 'DeploymentDefinitionValidator' || $basename === 'DeployPlanner' || $basename === 'PlanRenderer') {
                    continue;
                }

                if (!str_contains($code, 'final readonly class')) {
                    $violations[] = $dirName . '/' . $basename . ' is not final readonly';
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Definition/Plan VOs must be final readonly:\n  - " . implode("\n  - ", $violations),
        );
    }

    public function test_no_public_setters(): void
    {
        $root = dirname(__DIR__, 2);
        $dirs = ['Definition', 'Plan'];
        $violations = [];

        foreach ($dirs as $dirName) {
            $dir = $root . '/' . $dirName;
            if (!is_dir($dir)) {
                continue;
            }

            foreach (glob($dir . '/*.php') as $file) {
                $code = (string) file_get_contents($file);
                $basename = basename($file, '.php');

                if ($basename === 'DeploymentDefinitionBuilder' || $basename === 'LayeredDefinitionResolver' || $basename === 'DeploymentDefinitionValidator' || $basename === 'DeployPlanner' || $basename === 'PlanRenderer') {
                    continue;
                }

                if (preg_match('/public\s+function\s+set[A-Z]/', $code)) {
                    $violations[] = $dirName . '/' . $basename . ' has public setter(s)';
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Definition/Plan VOs must not have public setters:\n  - " . implode("\n  - ", $violations),
        );
    }
}
