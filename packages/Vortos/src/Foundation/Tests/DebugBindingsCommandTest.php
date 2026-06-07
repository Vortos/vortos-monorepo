<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Foundation\Command\DebugBindingsCommand;

final class DebugBindingsCommandTest extends TestCase
{
    private function tester(array $bindings): CommandTester
    {
        return new CommandTester(new DebugBindingsCommand($bindings));
    }

    public function test_shows_bindings_table(): void
    {
        $tester = $this->tester([
            'App\Contract\FooInterface' => ['class' => 'App\Impl\FooImpl', 'file' => '/src/Impl/FooImpl.php'],
            'App\Contract\BarInterface' => ['class' => 'App\Impl\BarImpl', 'file' => '/src/Impl/BarImpl.php'],
        ]);

        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('FooInterface', $output);
        $this->assertStringContainsString('FooImpl', $output);
        $this->assertStringContainsString('BarInterface', $output);
        $this->assertStringContainsString('BarImpl', $output);
        $this->assertStringContainsString('2 binding(s)', $output);
    }

    public function test_no_path_by_default(): void
    {
        $tester = $this->tester([
            'App\Contract\FooInterface' => ['class' => 'App\Impl\FooImpl', 'file' => '/src/Impl/FooImpl.php'],
        ]);

        $tester->execute([]);

        $this->assertStringNotContainsString('/src/Impl/FooImpl.php', $tester->getDisplay());
    }

    public function test_verbose_shows_file_path(): void
    {
        $tester = $this->tester([
            'App\Contract\FooInterface' => ['class' => 'App\Impl\FooImpl', 'file' => '/src/Impl/FooImpl.php'],
        ]);

        $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        $this->assertStringContainsString('/src/Impl/FooImpl.php', $tester->getDisplay());
    }

    public function test_empty_bindings_shows_warning(): void
    {
        $tester = $this->tester([]);
        $tester->execute([]);
        $this->assertStringContainsString('No #[DefaultImpl] bindings registered', $tester->getDisplay());
    }

    public function test_returns_success(): void
    {
        $tester = $this->tester(['App\Contract\FooInterface' => ['class' => 'App\Impl\FooImpl', 'file' => '']]);
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode());
    }
}
