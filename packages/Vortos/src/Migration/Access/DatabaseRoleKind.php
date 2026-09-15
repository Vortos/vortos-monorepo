<?php

declare(strict_types=1);

namespace Vortos\Migration\Access;

use Vortos\Persistence\Access\DatabaseRoleModel;
use Vortos\Persistence\Access\DatabaseRoleName;

/** The audiences of {@see DatabaseRoleModel}, each with the exact role attributes it must hold. */
enum DatabaseRoleKind: string
{
    case Bootstrap = 'bootstrap';
    case Owner = 'owner';
    case Runtime = 'runtime';
    case Backup = 'backup';

    public function roleIn(DatabaseRoleModel $model): ?DatabaseRoleName
    {
        return match ($this) {
            self::Bootstrap => $model->bootstrapSuperuser,
            self::Owner => $model->owner,
            self::Runtime => $model->runtime,
            self::Backup => $model->backup,
        };
    }

    /**
     * The attributes this audience must hold, as pg_roles columns. The bootstrap role is held only to SUPERUSER:
     * it is the break-glass account, and whether it may log in is governed by who can reach the local socket.
     *
     * @return array<string, bool>
     */
    public function attributes(): array
    {
        return match ($this) {
            self::Bootstrap => ['superuser' => true],
            self::Owner, self::Runtime => ['superuser' => false, 'replication' => false, 'createrole' => false, 'createdb' => false, 'bypassrls' => false, 'login' => true],
            // BYPASSRLS because pg_dump refuses a table whose policies would filter its rows (measured on 18:
            // "query would be affected by row-level security policy") — a dump that silently omitted another
            // tenant's rows would be worse. REPLICATION for pg_basebackup.
            self::Backup => ['superuser' => false, 'replication' => true, 'createrole' => false, 'createdb' => false, 'bypassrls' => true, 'login' => true],
        };
    }

    /**
     * Predefined roles this audience is a member of — exactly these, no more.
     *
     * Backup: pg_read_all_data (dump every table without per-table grants), pg_read_all_settings (the RC-5
     * config-drift probe), pg_monitor (archiver status, other sessions), pg_checkpoint (the RC-10 waker).
     *
     * @return list<string>
     */
    public function memberships(): array
    {
        return match ($this) {
            self::Backup => ['pg_checkpoint', 'pg_monitor', 'pg_read_all_data', 'pg_read_all_settings'],
            self::Bootstrap, self::Owner, self::Runtime => [],
        };
    }

    /** @return array<string, list<string>> the attribute keyword pairs for ALTER ROLE, in pg_roles column order */
    public static function keywords(): array
    {
        return [
            'superuser' => ['SUPERUSER', 'NOSUPERUSER'],
            'replication' => ['REPLICATION', 'NOREPLICATION'],
            'createrole' => ['CREATEROLE', 'NOCREATEROLE'],
            'createdb' => ['CREATEDB', 'NOCREATEDB'],
            'bypassrls' => ['BYPASSRLS', 'NOBYPASSRLS'],
            'login' => ['LOGIN', 'NOLOGIN'],
        ];
    }
}
