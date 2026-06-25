<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Fail-closed check that the host Postgres is actually configured for PITR before the
 * framework pretends WAL archiving is working.
 *
 * "PITR enabled but `archive_command` not wired" is exactly the failure that surfaces
 * only when you try to restore — so it is caught here (and, later, surfaced in
 * `deploy:doctor`). Returns a structured report; {@see assert()} throws on any gap.
 */
final class PitrPreflight
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{ok:bool, settings:array<string,string>, problems:list<string>}
     */
    public function check(): array
    {
        $settings = [];
        $problems = [];

        foreach (['archive_mode', 'archive_command', 'wal_level'] as $name) {
            $settings[$name] = $this->setting($name);
        }

        if ($settings['archive_mode'] !== 'on' && $settings['archive_mode'] !== 'always') {
            $problems[] = "archive_mode must be 'on' (got '{$settings['archive_mode']}').";
        }
        if (trim($settings['archive_command']) === '' || $settings['archive_command'] === '(disabled)') {
            $problems[] = 'archive_command is not configured (continuous WAL archiving is off).';
        }
        if (!in_array($settings['wal_level'], ['replica', 'logical'], true)) {
            $problems[] = "wal_level must be 'replica' or 'logical' (got '{$settings['wal_level']}').";
        }

        return ['ok' => $problems === [], 'settings' => $settings, 'problems' => $problems];
    }

    /** @throws PitrNotConfiguredException */
    public function assert(): void
    {
        $report = $this->check();
        if (!$report['ok']) {
            throw PitrNotConfiguredException::forProblems($report['problems']);
        }
    }

    private function setting(string $name): string
    {
        try {
            // SHOW is parameterless; the name is validated against a fixed allow-list above.
            $value = $this->connection->fetchOne('SHOW ' . $name);

            return is_scalar($value) ? (string) $value : '';
        } catch (Throwable) {
            return '';
        }
    }
}
