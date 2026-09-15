<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

final readonly class PostgresConfigDriftReport
{
    /** @param list<PostgresConfigDrift> $drift */
    private function __construct(
        public PostgresConfigDriftStatus $status,
        public ?string $declaredConfigFile,
        public array $drift,
        public ?string $reason,
    ) {}

    /** @param list<PostgresConfigDrift> $drift */
    public static function of(string $declaredConfigFile, array $drift): self
    {
        return new self($drift === [] ? PostgresConfigDriftStatus::Clean : PostgresConfigDriftStatus::Drifted, $declaredConfigFile, $drift, null);
    }

    public static function undeclared(): self
    {
        return new self(PostgresConfigDriftStatus::Undeclared, null, [], 'no PostgreSQL configuration file is declared (postgresConfigFile in config/backup.php)');
    }

    /** The settings could not be read; the exception class only, since a driver message can carry a DSN. */
    public static function indeterminate(string $exceptionClass): self
    {
        return new self(PostgresConfigDriftStatus::Indeterminate, null, [], sprintf('the running settings could not be read (%s)', $exceptionClass));
    }

    public static function unverifiable(string $declaredConfigFile): self
    {
        return new self(PostgresConfigDriftStatus::Unverifiable, $declaredConfigFile, [], 'the connection role cannot read setting sources or pg_file_settings; grant it pg_read_all_settings and SELECT on pg_file_settings');
    }

    /** @return array<string, mixed> */
    public function toDetail(): array
    {
        return [
            'status' => $this->status->value,
            'declared_config_file' => $this->declaredConfigFile,
            'drift' => array_map(static fn (PostgresConfigDrift $d): array => $d->toArray(), $this->drift),
            'reason' => $this->reason,
        ];
    }
}
