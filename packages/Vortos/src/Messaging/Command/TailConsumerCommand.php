<?php

declare(strict_types=1);

namespace Vortos\Messaging\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Messaging\Dev\TailRenderer;
use Vortos\Messaging\Hook\HandlerOutcome;

#[AsCommand(
    name: 'vortos:consumer:tail',
    description: 'Observe a running consumer\'s message activity in real time (dev tool)',
)]
final class TailConsumerCommand extends Command
{
    public function __construct(
        private readonly ?\Redis $redis,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('consumer', InputArgument::REQUIRED, 'Consumer name to observe');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->redis === null) {
            $output->writeln('<error>Redis is not configured — vortos:consumer:tail requires a Redis cache driver</error>');
            return Command::FAILURE;
        }

        $consumerName = $input->getArgument('consumer');
        $ctrlKey      = 'vortos:tail-ctrl:' . $consumerName;
        $channel      = 'vortos:tail:' . $consumerName;
        $renderer     = new TailRenderer($output);
        $stopping     = false;

        $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);
        $this->redis->set($ctrlKey, '1', ['ex' => 300]);

        $output->writeln(sprintf('<fg=gray>Tailing consumer:</> <info>%s</info>', $consumerName));
        $output->writeln('<fg=gray>Waiting for messages... Ctrl+C to stop.</>');
        $output->writeln('');

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () use (&$stopping): void {
                $stopping = true;
            });
            pcntl_signal(SIGTERM, function () use (&$stopping): void {
                $stopping = true;
            });
        }

        $callback = function (\Redis $redis, string $chan, string $message) use ($renderer, &$stopping, $channel): void {
            $data = json_decode($message, true);

            if (!is_array($data)) {
                return;
            }

            switch ($data['type'] ?? '') {
                case 'message_start':
                    $renderer->messageStart(
                        $data['event_id'],
                        $data['time'],
                        $data['event_short'],
                        $data['agg_id'],
                        $data['corr'],
                    );
                    break;

                case 'handler_retry':
                    $renderer->handlerRetry(
                        $data['event_id'],
                        $data['handler_id'],
                        (int) $data['attempt'],
                        (float) $data['latency_ms'],
                        (string) ($data['error'] ?? ''),
                    );
                    break;

                case 'handler_result':
                    $outcome = HandlerOutcome::tryFrom($data['outcome'] ?? '') ?? HandlerOutcome::Succeeded;
                    $renderer->handlerResult(
                        $data['event_id'],
                        $data['handler_id'],
                        $outcome,
                        (int) ($data['attempts'] ?? 1),
                        (float) $data['latency_ms'],
                        isset($data['error']) && $data['error'] !== null ? $data['error'] : null,
                    );
                    break;

                case 'event_flush':
                    $renderer->flush();
                    break;

                case 'stream_end':
                    $renderer->flush();
                    $stopping = true;
                    $redis->unsubscribe([$channel]);
                    break;
            }
        };

        try {
            $this->redis->subscribe([$channel], $callback);
        } catch (\Throwable) {
            // Signal interrupt (SIGINT/SIGTERM) — clean exit
        }

        $this->redis->del($ctrlKey);
        $renderer->flush();

        return Command::SUCCESS;
    }
}
