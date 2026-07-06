<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Backup\Console\BackupDoctorCommand;
use Vortos\Backup\Domain\EngineResolver;
use Vortos\Backup\Doctor\BackupToolchainInspector;
use Vortos\Backup\Port\BackupStoreInterface;
use Vortos\Backup\Port\BackupStoreRegistry;

final class BackupDoctorCommandTest extends TestCase
{
    public function test_passes_when_toolchain_and_store_are_healthy(): void
    {
        $tester = new CommandTester($this->command(
            probe: $this->presentProbe(),
            storeKeys: ['object-store'],
        ));

        $exit = $tester->execute(['--engine' => 'postgres']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Backup preflight passed.', $tester->getDisplay());
    }

    public function test_fails_closed_when_a_required_binary_is_missing(): void
    {
        $tester = new CommandTester($this->command(
            probe: static fn (string $b): ?array => $b === 'pg_dump' ? null : ['path' => "/usr/bin/{$b}", 'major' => 18],
            storeKeys: ['object-store'],
        ));

        $exit = $tester->execute(['--engine' => 'postgres']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('pg_dump not found', $tester->getDisplay());
        $this->assertStringContainsString('Backup preflight failed', $tester->getDisplay());
    }

    public function test_fails_closed_when_no_engine_configured(): void
    {
        $tester = new CommandTester($this->command(
            probe: $this->presentProbe(),
            storeKeys: ['object-store'],
            configuredDefault: null,
        ));

        $exit = $tester->execute([]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No backup engine configured', $tester->getDisplay());
    }

    public function test_fails_closed_when_store_does_not_resolve(): void
    {
        $tester = new CommandTester($this->command(
            probe: $this->presentProbe(),
            storeKeys: [], // the configured store key is absent
        ));

        $exit = $tester->execute(['--engine' => 'postgres']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('does not resolve', $tester->getDisplay());
    }

    public function test_json_output_reports_ok_and_toolchain(): void
    {
        $tester = new CommandTester($this->command(
            probe: $this->presentProbe(),
            storeKeys: ['object-store'],
        ));

        $tester->execute(['--engine' => 'postgres', '--json' => true]);

        $decoded = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($decoded['ok']);
        $this->assertSame('postgres', $decoded['toolchain']['engine']);
        $this->assertTrue($decoded['store']['resolved']);
    }

    private function presentProbe(): \Closure
    {
        return static fn (string $b): ?array => ['path' => "/usr/bin/{$b}", 'major' => 18];
    }

    /** @param list<string> $storeKeys */
    private function command(\Closure $probe, array $storeKeys, ?string $configuredDefault = 'postgres'): BackupDoctorCommand
    {
        return new BackupDoctorCommand(
            new EngineResolver($configuredDefault),
            new BackupToolchainInspector($probe),
            new BackupStoreRegistry($this->storeContainer($storeKeys)),
            'object-store',
            null,
        );
    }

    /** @param list<string> $keys */
    private function storeContainer(array $keys): ContainerInterface
    {
        $store = $this->createStub(BackupStoreInterface::class);

        return new class ($keys, $store) implements ContainerInterface {
            /** @param list<string> $keys */
            public function __construct(private array $keys, private BackupStoreInterface $store)
            {
            }

            public function has(string $id): bool
            {
                return in_array($id, $this->keys, true);
            }

            public function get(string $id): mixed
            {
                if (!$this->has($id)) {
                    throw new class ('missing') extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
                    };
                }

                return $this->store;
            }
        };
    }
}
