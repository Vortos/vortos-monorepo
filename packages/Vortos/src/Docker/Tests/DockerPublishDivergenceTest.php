<?php

declare(strict_types=1);

namespace Vortos\Docker\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Docker\Service\DockerFilePublisher;

/**
 * Publishing one file must not revert the others.
 *
 * This exists because it did. Taking a one-line Caddyfile change into an app also rewrote its
 * Dockerfile, its production compose file, its worker supervisord config and its .dockerignore
 * back to framework stubs — undoing an image-slimming build stage, a blue/green topology, and a
 * non-root worker with a hardened control socket. The command reported "6 Docker files published"
 * and said nothing about what it had removed. The `.bak` files made it recoverable; noticing was
 * left entirely to whoever read the diff.
 *
 * So: a file that already differs is never overwritten unless asked for, and `--only` exists for
 * the case that started it.
 *
 * @see DockerFilePublisher
 */
final class DockerPublishDivergenceTest extends TestCase
{
    private const STUB_ROOT = __DIR__ . '/../stubs';

    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/vortos-publish-' . bin2hex(random_bytes(6));
        mkdir($this->project, 0755, true);
    }

    /** @param array<string, mixed> $options */
    private function publish(array $options = [], bool $overwrite = true): \Vortos\Docker\Service\DockerPublishResult
    {
        return (new DockerFilePublisher(self::STUB_ROOT))->publish(
            'frankenphp',
            $this->project,
            false,
            false,
            $overwrite,
            $options + ['features' => ['mercure' => false], 'corsOrigins' => ['https://app.example.com']],
        );
    }

    private function writeLocal(string $relativePath, string $contents): void
    {
        $target = $this->project . '/' . $relativePath;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }
        file_put_contents($target, $contents);
    }

    // ── The regression ────────────────────────────────────────────────────────

    public function test_a_locally_edited_file_is_not_overwritten(): void
    {
        $this->publish();                                   // first publish: everything lands
        $this->writeLocal('docker/php/Dockerfile', "# hand-tuned\nFROM scratch\n");

        $result = $this->publish();

        self::assertSame(
            "# hand-tuned\nFROM scratch\n",
            file_get_contents($this->project . '/docker/php/Dockerfile'),
            'the local Dockerfile must survive a publish',
        );
        self::assertArrayHasKey('docker/php/Dockerfile', $result->diverged);
        self::assertContains('docker/php/Dockerfile', $result->skipped);
        self::assertNotContains('docker/php/Dockerfile', $result->copied);
    }

    /** The size of what would be lost is the signal an operator needs, so it is reported. */
    public function test_divergence_reports_how_much_would_change(): void
    {
        $this->publish();
        $this->writeLocal('docker/php/Dockerfile', str_repeat("# local\n", 500));

        $result = $this->publish();

        self::assertSame(500, $result->diverged['docker/php/Dockerfile']['current']);
        self::assertGreaterThan(0, $result->diverged['docker/php/Dockerfile']['new']);
        self::assertTrue($result->hasDiverged());
    }

    public function test_force_overwrites_a_locally_edited_file(): void
    {
        $this->publish();
        $this->writeLocal('docker/php/Dockerfile', "# hand-tuned\n");

        $result = $this->publish(['overwriteDiverged' => true]);

        self::assertStringNotContainsString('hand-tuned', (string) file_get_contents($this->project . '/docker/php/Dockerfile'));
        self::assertContains('docker/php/Dockerfile', $result->copied);
        self::assertArrayHasKey('docker/php/Dockerfile', $result->diverged, 'still reported, even when taken');
    }

    // ── --only ────────────────────────────────────────────────────────────────

    public function test_only_publishes_the_named_file_and_leaves_the_rest_alone(): void
    {
        $this->publish();
        $this->writeLocal('docker/php/Dockerfile', "# hand-tuned\n");
        $this->writeLocal('docker/frankenphp/Caddyfile', "# stale\n");

        $result = $this->publish([
            'only'              => ['docker/frankenphp/Caddyfile'],
            'overwriteDiverged' => true,
        ]);

        self::assertSame(['docker/frankenphp/Caddyfile'], $result->copied);
        self::assertSame(
            "# hand-tuned\n",
            file_get_contents($this->project . '/docker/php/Dockerfile'),
            'a file outside --only must not be touched even with force',
        );
    }

    public function test_only_accepts_a_directory_prefix(): void
    {
        $result = $this->publish(['only' => ['docker/frankenphp']]);

        self::assertNotSame([], $result->copied);
        foreach ($result->copied as $path) {
            self::assertStringStartsWith('docker/frankenphp/', $path);
        }
    }

    /** A prefix must match on a path segment, or `docker/f` would sweep in unrelated siblings. */
    public function test_only_does_not_match_a_partial_segment(): void
    {
        $result = $this->publish(['only' => ['docker/franken']]);

        self::assertSame([], $result->copied);
    }

    // ── Unchanged behaviour ───────────────────────────────────────────────────

    public function test_a_first_publish_still_writes_everything(): void
    {
        $result = $this->publish();

        self::assertNotSame([], $result->copied);
        self::assertSame([], $result->diverged, 'nothing exists yet, so nothing can have diverged');
    }

    public function test_an_identical_file_is_skipped_not_reported_as_diverged(): void
    {
        $this->publish();

        $result = $this->publish();

        self::assertSame([], $result->copied);
        self::assertSame([], $result->diverged);
        self::assertNotSame([], $result->skipped);
    }
}
