<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\FlagSync;

use Throwable;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

/**
 * Reconciles Vortos flag definitions into PostHog so every flag exists there as a real
 * entity — which is what makes it visible in the Feature Flags UI and attachable to an
 * Experiment.
 *
 * Vortos is unconditionally the source of truth. The reconciliation is one-directional and
 * additive: flags are created and drifted fields are patched, but a flag that exists only in
 * PostHog is never deleted, and a flag PostHog owns (one without the `vortos` tag) is never
 * touched at all — deleting someone's hand-made flag because it was absent from our storage
 * would be a far worse failure than leaving a stale entry behind.
 */
final readonly class PosthogFlagSynchronizer
{
    public function __construct(
        private FlagStorageInterface $storage,
        private PosthogFlagApiInterface $client,
        private FlagDefinitionMapper $mapper = new FlagDefinitionMapper(),
    ) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * Mirror every known flag.
     *
     * One list call up front, then at most one write per flag that actually differs — so a
     * steady-state run costs a single request no matter how many flags exist.
     *
     * @throws PosthogApiException when the flag list itself cannot be fetched (nothing can proceed)
     */
    public function syncAll(bool $dryRun = false): FlagSyncReport
    {
        return $this->sync($this->storage->findAll(), $dryRun);
    }

    /**
     * Mirror a single flag by name — the path taken when a flag is created or changed, so it
     * appears in PostHog without waiting for the next full reconciliation.
     *
     * @throws PosthogApiException
     */
    public function syncOne(string $flagName, bool $dryRun = false): FlagSyncReport
    {
        $flags = array_values(array_filter(
            $this->storage->findAll(),
            static fn (FeatureFlag $flag): bool => $flag->name === $flagName,
        ));

        return $this->sync($flags, $dryRun);
    }

    /**
     * @param list<FeatureFlag> $flags
     * @throws PosthogApiException
     */
    private function sync(array $flags, bool $dryRun): FlagSyncReport
    {
        $report = new FlagSyncReport($dryRun);

        if ($flags === []) {
            return $report;
        }

        // Fetched once, outside the loop. A throw here is fatal to the whole run on purpose:
        // without the remote list we cannot tell "missing" from "already there", and guessing
        // would mean POSTing duplicates.
        $remote = $this->client->listFlags();

        foreach ($flags as $flag) {
            try {
                $this->syncFlag($flag, $remote, $report, $dryRun);
            } catch (Throwable $e) {
                // One rejected flag must not abandon the rest — a single bad definition would
                // otherwise block every flag after it, alphabetically, forever.
                $report->failed[$flag->name] = $e->getMessage();
            }
        }

        return $report;
    }

    /**
     * @param array<string,array<string,mixed>> $remote
     * @throws PosthogApiException
     */
    private function syncFlag(FeatureFlag $flag, array $remote, FlagSyncReport $report, bool $dryRun): void
    {
        // Looked up by the SANITISED key, because that is what the flag was created under.
        // Indexing by the raw Vortos name would find nothing for every dotted flag, so each
        // run would try to create one that already exists and report a duplicate forever.
        $existing = $remote[PosthogFlagKey::forPosthog($flag->name)] ?? null;

        if ($existing === null) {
            if (!$dryRun) {
                $this->client->createFlag($this->mapper->toPayload($flag));
            }
            $report->created[] = $flag->name;

            return;
        }

        if (!$this->isManaged($existing)) {
            // Authored in PostHog by a human. Leave it alone and say so, rather than silently
            // overwriting their configuration with an inert 0% mirror.
            $report->failed[$flag->name] = 'exists in PostHog without the `'
                . FlagDefinitionMapper::MANAGED_TAG . '` tag — not overwriting a hand-made flag';

            return;
        }

        if (!$this->mapper->differs($flag, $existing)) {
            $report->unchanged[] = $flag->name;

            return;
        }

        $id = $existing['id'] ?? null;
        if (!is_int($id)) {
            $report->failed[$flag->name] = 'PostHog returned this flag without a numeric id';

            return;
        }

        if (!$dryRun) {
            $this->client->updateFlag($id, $this->mapper->toPayload($flag));
        }
        $report->updated[] = $flag->name;
    }

    /** @param array<string,mixed> $remote */
    private function isManaged(array $remote): bool
    {
        $tags = $remote['tags'] ?? [];

        return is_array($tags) && in_array(FlagDefinitionMapper::MANAGED_TAG, $tags, true);
    }
}
