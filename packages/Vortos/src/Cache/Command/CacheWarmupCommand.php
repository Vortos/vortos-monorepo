<?php

declare(strict_types=1);

namespace Vortos\Cache\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Cache\Contract\CacheWarmerInterface;

/**
 * Runs all registered cache warmers.
 *
 * Cache warmers pre-populate the cache before traffic hits the application.
 * Run this after deployment once the cache has been cleared:
 *
 *   php bin/console vortos:cache:clear
 *   php bin/console vortos:cache:warmup
 *
 * ## Registering a warmer
 *
 * Implement CacheWarmerInterface and tag the service 'vortos.cache_warmer':
 *
 *   use Vortos\Cache\Contract\CacheWarmerInterface;
 *   use Vortos\Cache\Contract\TaggedCacheInterface;
 *
 *   final class AthleteRankingsCacheWarmer implements CacheWarmerInterface
 *   {
 *       public function __construct(
 *           private AthleteRepository $repository,
 *           private TaggedCacheInterface $cache,
 *       ) {}
 *
 *       public function warmUp(): void
 *       {
 *           $rankings = $this->repository->getTopRankings(limit: 100);
 *           $this->cache->setWithTags(
 *               'athlete:rankings:top100',
 *               $rankings,
 *               ['athlete:rankings'],
 *               ttl: 3600,
 *           );
 *       }
 *   }
 *
 * Register in config/services.php:
 *
 *   $services->set(AthleteRankingsCacheWarmer::class)
 *       ->tag('vortos.cache_warmer');
 *
 * ## Squaura real-world warmers
 *
 *   - CompetitionRulesCacheWarmer — rules/scoring formats change rarely, queried constantly
 *   - PermissionMatrixCacheWarmer — RBAC roles and permissions per tenant
 *   - ActiveTournamentsCacheWarmer — current competitions, athlete eligibility
 *   - FederationConfigCacheWarmer — federation-level settings per country
 */
#[AsCommand(
    name: 'vortos:cache:warmup',
    description: 'Run all registered cache warmers',
)]
final class CacheWarmupCommand extends Command
{
    /** @param iterable<CacheWarmerInterface> $warmers */
    public function __construct(private iterable $warmers)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Vortos Cache Warmup</info>');
        $output->writeln('');

        $count = 0;

        foreach ($this->warmers as $warmer) {
            $name = get_class($warmer);

            try {
                $warmer->warmUp();
                $output->writeln(sprintf('  <info>✔</info> %s', $name));
                $count++;
            } catch (\Throwable $e) {
                $output->writeln(sprintf('  <error>✘ %s: %s</error>', $name, $e->getMessage()));
            }
        }

        $output->writeln('');

        if ($count === 0) {
            $output->writeln('<comment>No cache warmers registered. Tag your warmers with vortos.cache_warmer.</comment>');
        } else {
            $output->writeln(sprintf('<info>Done. %d warmer(s) ran successfully.</info>', $count));
        }

        return Command::SUCCESS;
    }
}
