<?php

declare(strict_types=1);

namespace Vortos\Logger\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Dumps the resolved logging topology — every channel, the sinks it routes
 * to, and each sink's destination/buffering/rotation configuration — so
 * operators can confirm the effective pipeline without reading config code.
 *
 * ## Usage
 *
 *   php bin/console vortos:logs:diagnose
 */
#[AsCommand(
    name: 'vortos:logs:diagnose',
    description: 'Show the resolved logging channel/sink topology',
)]
final class LogDiagnoseCommand extends Command
{
    /**
     * @param array{channels: array<string, array{sinkIds: list<string>, level: string, disabled: bool}>, sinks: array<string, array{destination: string, path: ?string, level: string, bufferPolicy: string, sampleFactor: ?int, hashChain: bool, flushIntervalSeconds: int, rotation: array{enabled: bool, maxFiles: int, maxAgeDays: int, maxTotalSizeMb: int, compress: bool}}>} $topology
     */
    public function __construct(private array $topology)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->section('Channels');

        $channelRows = [];
        foreach ($this->topology['channels'] as $name => $channel) {
            $channelRows[] = [
                $name,
                $channel['disabled'] ? 'disabled' : $channel['level'],
                $channel['disabled'] ? '-' : implode(', ', $channel['sinkIds']),
            ];
        }
        $io->table(['Channel', 'Level', 'Sinks'], $channelRows);

        $io->section('Sinks');

        $sinkRows = [];
        foreach ($this->topology['sinks'] as $id => $sink) {
            $destination = $sink['destination'];
            if ($sink['path'] !== null) {
                $destination .= ' (' . $sink['path'] . ')';
            }

            $rotation = $sink['rotation']['enabled']
                ? sprintf(
                    'max %d files / %d days / %d MB%s',
                    $sink['rotation']['maxFiles'],
                    $sink['rotation']['maxAgeDays'],
                    $sink['rotation']['maxTotalSizeMb'],
                    $sink['rotation']['compress'] ? ', gzip' : '',
                )
                : 'disabled';

            $sinkRows[] = [
                $id,
                $destination,
                $sink['level'],
                $sink['bufferPolicy'],
                $sink['sampleFactor'] !== null ? '1/' . $sink['sampleFactor'] : '-',
                $sink['hashChain'] ? 'yes' : 'no',
                $rotation,
            ];
        }
        $io->table(['Sink', 'Destination', 'Level', 'Buffer', 'Sampling', 'Hash chain', 'Rotation'], $sinkRows);

        return Command::SUCCESS;
    }
}
