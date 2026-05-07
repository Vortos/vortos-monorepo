<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Cache\Contract\TaggedCacheInterface;

/**
 * Clears only the Doctrine ORM metadata cache.
 *
 * Uses tag-based invalidation to surgically remove ORM metadata entries
 * without touching any other cached data (flags, auth tokens, read models).
 *
 * Run as part of your deployment pipeline after changing entity mappings:
 *
 *   php bin/console vortos:migrate
 *   php bin/console vortos:orm:clear-cache
 *
 * vortos:cache:clear also covers ORM metadata (it clears everything), but
 * this command is preferable when you only changed entity mappings and want
 * to avoid invalidating unrelated cache entries.
 *
 * Only registered when the Cache module is loaded (TaggedCacheInterface available).
 */
#[AsCommand(
    name: 'vortos:orm:clear-cache',
    description: 'Clear Doctrine ORM metadata cache (surgical — does not affect other cached data)',
)]
final class OrmClearCacheCommand extends Command
{
    public function __construct(private readonly TaggedCacheInterface $cache)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cache->invalidateTags(['orm_metadata']);

        $output->writeln('<info>✔ ORM metadata cache cleared.</info>');
        $output->writeln('  Doctrine will re-read entity mappings on next boot.');

        return Command::SUCCESS;
    }
}
