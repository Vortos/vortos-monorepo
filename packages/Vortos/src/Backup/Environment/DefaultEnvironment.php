<?php

declare(strict_types=1);

namespace Vortos\Backup\Environment;

/**
 * The single canonical environment label for backup commands.
 *
 * R7-6: backups are cataloged under whatever `--env` value `backup:run` used; `backup:list`,
 * `backup:retention` and `backup:drill` silently see nothing if their `--env` default differs.
 * Standardizing every backup command on ONE label — matching the deploy + release manifests
 * (`deployment.environment`), which use `production` — closes that gap. Reference this constant
 * everywhere so the default can never drift again.
 */
final class DefaultEnvironment
{
    public const NAME = 'production';
}
