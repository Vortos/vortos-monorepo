<?php

declare(strict_types=1);

namespace Vortos\Backup\Domain;

use Vortos\Backup\Domain\Exception\EngineNotConfiguredException;

/**
 * The single, fail-closed rule for choosing a backup engine.
 *
 * Precedence: an explicit `--engine` flag wins; otherwise the configured default
 * (`VORTOS_BACKUP_ENGINE`); otherwise we refuse — never a silent guess. Every command and the
 * scheduled fragment resolve through here so the CLI, cron, and doctor agree byte-for-byte.
 */
final class EngineResolver
{
    private readonly ?string $configuredDefault;

    public function __construct(?string $configuredDefault = null)
    {
        $normalized = $configuredDefault !== null ? trim($configuredDefault) : '';
        $this->configuredDefault = $normalized === '' ? null : $normalized;
    }

    /**
     * @throws EngineNotConfiguredException when neither a flag nor a configured default is present
     * @throws Exception\UnknownEngineException when the chosen value names no known engine
     */
    public function resolve(?string $flag = null): DatabaseEngine
    {
        $candidate = $this->firstNonEmpty($flag) ?? $this->configuredDefault;

        if ($candidate === null) {
            throw EngineNotConfiguredException::create(DatabaseEngine::all());
        }

        return DatabaseEngine::fromString($candidate);
    }

    /** The configured default as a raw string, or null — for doctor/diagnostic display. */
    public function configuredDefault(): ?string
    {
        return $this->configuredDefault;
    }

    private function firstNonEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
