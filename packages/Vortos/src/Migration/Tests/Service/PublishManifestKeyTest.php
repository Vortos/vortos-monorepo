<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Service\PublishManifestKey;

final class PublishManifestKeyTest extends TestCase
{
    /**
     * The invariant that matters: whatever the publisher WRITES, the detector must READ back as
     * published. When these two held separate copies of the rule and only one changed, auto-publish
     * emitted a migration and then refused the deploy because it could not find the stub it had
     * just published.
     */
    public function test_what_the_publisher_writes_the_detector_reads_as_published(): void
    {
        $stub = [
            'module'      => 'Messaging',
            'filename'    => '001_outbox.sql',
            'relative'    => 'vendor/vortos/messaging/Resources/migrations/001_outbox.sql',
            'is_provider' => false,
        ];

        $manifest = [PublishManifestKey::canonical($stub) => ['version' => 'Version20260727051802']];

        self::assertSame('Messaging/001_outbox.sql', PublishManifestKey::resolve($stub, $manifest));
    }

    public function test_a_genuinely_new_stub_is_unresolved(): void
    {
        $stub = [
            'module'   => 'Scheduler',
            'filename' => '002_cursors.sql',
            'relative' => 'x/002_cursors.sql',
        ];

        self::assertNull(PublishManifestKey::resolve($stub, ['Messaging/001_outbox.sql' => []]));
    }

    /**
     * The destructive direction. A manifest written before the key format changed must still be
     * recognised — reading those stubs as new regenerated 53 migrations for schema already live in
     * production.
     */
    public function test_legacy_relative_keys_are_still_recognised(): void
    {
        $stub = [
            'module'   => 'Messaging',
            'filename' => '001_outbox.sql',
            'relative' => 'vendor/vortos/messaging/Resources/migrations/001_outbox.sql',
        ];

        self::assertSame(
            $stub['relative'],
            PublishManifestKey::resolve($stub, [$stub['relative'] => ['version' => 'V1']]),
        );
    }

    public function test_legacy_absolute_mount_path_keys_are_still_recognised(): void
    {
        $stub = [
            'module'   => 'Messaging',
            'filename' => '001_outbox.sql',
            'relative' => 'vendor/vortos/messaging/Resources/migrations/001_outbox.sql',
        ];

        $manifest = ['app/vendor/vortos/messaging/Resources/migrations/Messaging/001_outbox.sql' => []];

        self::assertNotNull(PublishManifestKey::resolve($stub, $manifest));
    }

    public function test_a_provider_recorded_under_the_sql_stub_it_superseded_is_recognised(): void
    {
        $stub = [
            'module'      => 'Scheduler',
            'filename'    => '003_cursor.php',
            'relative'    => 'vendor/vortos/scheduler/Resources/migrations/003_cursor.php',
            'is_provider' => true,
        ];

        $manifest = ['vendor/vortos/scheduler/Resources/migrations/003_cursor.sql' => []];

        self::assertSame(
            'vendor/vortos/scheduler/Resources/migrations/003_cursor.sql',
            PublishManifestKey::resolve($stub, $manifest),
        );
    }
}
