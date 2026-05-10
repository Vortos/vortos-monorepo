<?php

declare(strict_types=1);

namespace Vortos\Mcp\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Mcp\Client\ClientConfigWriter;
use Vortos\Mcp\Client\ClientDetector;
use Vortos\Mcp\Client\KnownClients;
use Vortos\Mcp\Command\McpDoctorCommand;
use Vortos\Mcp\Command\McpInstallCommand;
use Vortos\Mcp\Command\McpServeCommand;
use Vortos\Mcp\Server\McpServer;
use Vortos\Mcp\Server\StdioTransport;
use Vortos\Mcp\Tool\GetArchitectureTool;
use Vortos\Mcp\Tool\GetBestPracticesTool;
use Vortos\Mcp\Tool\GetConventionsTool;
use Vortos\Mcp\Tool\GetMistakesTool;
use Vortos\Mcp\Tool\GetModuleDocsTool;
use Vortos\Mcp\Tool\ListProjectModulesTool;
use Vortos\Mcp\Tool\ReadProjectConfigTool;

final class McpExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_mcp';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');

        // Tools
        foreach ([
            GetConventionsTool::class,
            GetModuleDocsTool::class,
            GetArchitectureTool::class,
            GetBestPracticesTool::class,
            GetMistakesTool::class,
        ] as $toolClass) {
            $container->register($toolClass, $toolClass)->setPublic(false);
        }

        $container->register(ListProjectModulesTool::class, ListProjectModulesTool::class)
            ->setArgument('$projectDir', $projectDir)
            ->setPublic(false);

        $container->register(ReadProjectConfigTool::class, ReadProjectConfigTool::class)
            ->setArgument('$projectDir', $projectDir)
            ->setPublic(false);

        // Transport + server
        $container->register(StdioTransport::class, StdioTransport::class)->setPublic(false);

        $server = $container->register(McpServer::class, McpServer::class)
            ->setArgument('$transport', new Reference(StdioTransport::class))
            ->setPublic(false);

        foreach ([
            GetConventionsTool::class,
            GetModuleDocsTool::class,
            GetArchitectureTool::class,
            GetBestPracticesTool::class,
            GetMistakesTool::class,
            ListProjectModulesTool::class,
            ReadProjectConfigTool::class,
        ] as $toolClass) {
            $server->addMethodCall('addTool', [new Reference($toolClass)]);
        }

        // Client detection + config writing
        $container->register(KnownClients::class, KnownClients::class)->setPublic(false);

        $container->register(ClientDetector::class, ClientDetector::class)
            ->setArgument('$projectDir', $projectDir)
            ->setArgument('$knownClients', new Reference(KnownClients::class))
            ->setPublic(false);

        $container->register(ClientConfigWriter::class, ClientConfigWriter::class)
            ->setArgument('$knownClients', new Reference(KnownClients::class))
            ->setPublic(false);

        // Commands
        $container->register(McpServeCommand::class, McpServeCommand::class)
            ->setArgument('$server', new Reference(McpServer::class))
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(McpInstallCommand::class, McpInstallCommand::class)
            ->setArgument('$projectDir', $projectDir)
            ->setArgument('$detector', new Reference(ClientDetector::class))
            ->setArgument('$writer', new Reference(ClientConfigWriter::class))
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(McpDoctorCommand::class, McpDoctorCommand::class)
            ->setArgument('$projectDir', $projectDir)
            ->setArgument('$detector', new Reference(ClientDetector::class))
            ->setArgument('$server', new Reference(McpServer::class))
            ->setPublic(true)
            ->addTag('console.command');
    }
}
