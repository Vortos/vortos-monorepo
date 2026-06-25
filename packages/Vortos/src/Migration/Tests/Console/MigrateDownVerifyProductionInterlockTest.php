<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Console;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Vortos\Migration\Console\MigrateDownVerifyCommand;
use Vortos\Migration\Safety\MigrationArtifactFactoryInterface;
use Vortos\Migration\Service\DependencyFactoryProviderInterface;

final class MigrateDownVerifyProductionInterlockTest extends TestCase
{
    public function test_refuses_to_run_in_prod_environment(): void
    {
        $_ENV['APP_ENV'] = 'prod';

        $command = $this->buildCommand();
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $input->bind($command->getDefinition());

        $code = $command->run($input, $output);

        unset($_ENV['APP_ENV']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('refuses', strtolower($output->fetch()));
    }

    private function buildCommand(): MigrateDownVerifyCommand
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabase')->willReturn('app_db');

        $factoryProvider = $this->createMock(DependencyFactoryProviderInterface::class);
        $artifactFactory = $this->createMock(MigrationArtifactFactoryInterface::class);

        return new MigrateDownVerifyCommand($connection, $factoryProvider, $artifactFactory);
    }
}
