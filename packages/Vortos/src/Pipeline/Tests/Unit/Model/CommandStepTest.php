<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Model\CommandStep;

final class CommandStepTest extends TestCase
{
    public function test_construction(): void
    {
        $step = new CommandStep('Run tests', './vendor/bin/phpunit --testdox');

        $this->assertSame('Run tests', $step->name);
        $this->assertSame('./vendor/bin/phpunit --testdox', $step->run);
        $this->assertNull($step->workingDirectory);
        $this->assertNull($step->condition);
    }

    public function test_with_working_directory(): void
    {
        $step = new CommandStep('Install', 'npm install', workingDirectory: 'packages/admin');

        $this->assertSame('packages/admin', $step->workingDirectory);
    }

    public function test_with_condition(): void
    {
        $step = new CommandStep('Deploy', 'deploy', condition: "github.ref == 'refs/heads/main'");

        $this->assertSame("github.ref == 'refs/heads/main'", $step->condition);
    }

    public function test_to_array(): void
    {
        $step = new CommandStep('Run', 'phpunit', 'src', 'always()');

        $array = $step->toArray();

        $this->assertSame('command', $array['type']);
        $this->assertSame('Run', $array['name']);
        $this->assertSame('phpunit', $array['run']);
        $this->assertSame('src', $array['working_directory']);
        $this->assertSame('always()', $array['condition']);
    }

    public function test_to_array_minimal(): void
    {
        $step = new CommandStep('Run', 'phpunit');

        $array = $step->toArray();

        $this->assertArrayNotHasKey('working_directory', $array);
        $this->assertArrayNotHasKey('condition', $array);
    }

    public function test_empty_name_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CommandStep('', 'phpunit');
    }

    public function test_empty_run_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CommandStep('Run', '');
    }
}
