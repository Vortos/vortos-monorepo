<?php

declare(strict_types=1);

namespace App\Athlete\Infrastructure\Authorization;

use Vortos\Authorization\Attribute\PermissionCatalog;
use Vortos\Authorization\Permission\AbstractPermissionCatalog;

#[PermissionCatalog(resource: 'athletes', group: 'Athletes')]
final class AthletePermissions extends AbstractPermissionCatalog
{
    public const ListAny = 'list.any';
    public const ReadAny = 'read.any';
    public const CreateAny = 'create.any';
    public const UpdateOwn = 'update.own';
    public const UpdateAny = 'update.any';
    public const DeleteAny = 'delete.any';

    public static function grants(): array
    {
        return [
            'ROLE_USER' => [
                self::ListAny,
                self::ReadAny,
            ],
            'ROLE_COACH' => [
                self::CreateAny,
                self::UpdateOwn,
            ],
            'ROLE_ADMIN' => [
                self::UpdateAny,
                self::DeleteAny,
            ],
        ];
    }

    public static function meta(): array
    {
        return [
            self::DeleteAny => self::dangerous(
                'Delete any athlete',
                'Allows deleting athlete records across the application.',
            ),
        ];
    }
}
