<?php

declare(strict_types=1);

namespace Vortos\Backup\Restore\Driver\Postgres;

use RuntimeException;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Restore\Capability\RestoreTargetCapability;
use Vortos\Backup\Restore\RestoreRequest;
use Vortos\Backup\Restore\RestoreTargetInterface;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('postgres')]
final class PostgresRestoreTarget implements RestoreTargetInterface
{
    public function __construct(
        private readonly PostgresRestoreProcessFactory $processes,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            RestoreTargetCapability::StreamingRestore->value => true,
            RestoreTargetCapability::CleanRestore->value => true,
            RestoreTargetCapability::PointInTime->value => false,
        ]);
    }

    public function engine(): DatabaseEngine
    {
        return DatabaseEngine::Postgres;
    }

    public function restore(iterable $chunks, RestoreRequest $request): void
    {
        $params = $this->parseDsn($request->destinationDsn);

        [$stdin, $guard] = $this->processes->pgRestore(
            $params['host'],
            $params['port'],
            $params['user'],
            $params['password'],
            $params['dbname'],
        );

        try {
            foreach ($chunks as $chunk) {
                $written = fwrite($stdin, $chunk);
                if ($written === false) {
                    throw new RuntimeException('Failed to write to pg_restore stdin.');
                }
            }
        } finally {
            fclose($stdin);
        }

        $guard->assertSuccess();
    }

    /**
     * @return array{host:string, port:int, user:string, password:string, dbname:string}
     */
    private function parseDsn(string $dsn): array
    {
        $parsed = parse_url($dsn);
        if ($parsed === false || !isset($parsed['scheme'])) {
            throw new RuntimeException(sprintf('Invalid Postgres DSN: %s', $dsn));
        }

        return [
            'host' => $parsed['host'] ?? 'localhost',
            'port' => $parsed['port'] ?? 5432,
            'user' => $parsed['user'] ?? 'postgres',
            'password' => isset($parsed['pass']) ? urldecode($parsed['pass']) : '',
            'dbname' => ltrim($parsed['path'] ?? '/postgres', '/'),
        ];
    }
}
