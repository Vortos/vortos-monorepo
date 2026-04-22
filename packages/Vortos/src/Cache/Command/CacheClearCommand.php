<?php

declare(strict_types=1);

namespace Vortos\Cache\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Cache\Contract\TaggedCacheInterface;

/**
 * Clears the cache or invalidates specific tags.
 *
 * ## Usage
 *
 *   php bin/console vortos:cache:clear
 *   php bin/console vortos:cache:clear --tag=user:123
 *   php bin/console vortos:cache:clear --tag=user:123 --tag=profiles
 *
 * Without --tag: clears ALL keys matching the configured prefix.
 * Uses SCAN + DEL — never FLUSHDB. Only this app's prefixed keys are removed.
 *
 * With --tag: calls invalidateTags() for each specified tag.
 * Only keys tagged with those tags are removed — surgical invalidation.
 *
 * ## Deployment workflow
 *
 *   php bin/console vortos:cache:clear    # clear stale cache after deploy
 *   php bin/console vortos:cache:warmup   # pre-populate critical cache entries
 */
#[AsCommand(
    name: 'vortos:cache:clear',
    description: 'Clear the cache or invalidate specific tags',
)]
final class CacheClearCommand extends Command
{
    public function __construct(private TaggedCacheInterface $cache)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'tag',
            't',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Tag(s) to invalidate. If not specified, clears all prefixed keys.',
            [],
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tags = $input->getOption('tag');

        if (!empty($tags)) {
            $this->cache->invalidateTags($tags);

            $output->writeln(sprintf(
                '<info>✔ Invalidated tags:</info> %s',
                implode(', ', $tags),
            ));

            return Command::SUCCESS;
        }

        $this->cache->clear();

        $output->writeln('<info>✔ Cache cleared.</info>');
        $output->writeln('<comment>Run vortos:cache:warmup to pre-populate critical entries.</comment>');

        return Command::SUCCESS;
    }
}
