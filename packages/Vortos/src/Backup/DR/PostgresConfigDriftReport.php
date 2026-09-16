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
        return new self(PostgresConfigDriftStatus::Unverifiable, $declaredConfigFile, [], 'this role watches the cluster but cannot read pg_file_settings; grant it SELECT on pg_catalog.pg_file_settings and EXECUTE on pg_show_all_file_settings()');
    }

    /** Not this node's question: its role holds no cluster-wide settings visibility, and is not meant to. */
    public static function notWatchedHere(string $declaredConfigFile): self
    {
        return new self(PostgresConfigDriftStatus::NotWatchedHere, $declaredConfigFile, [], 'this connection role is not a cluster-watching role (no pg_read_all_settings), so the configuration is checked on the node that is');
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
