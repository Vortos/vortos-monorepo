<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Emitter;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Emitter\EmittedArtifact;
use Vortos\Pipeline\Emitter\EmittedArtifactSet;

final class EmittedArtifactSetTest extends TestCase
{
    public function test_count(): void
    {
        $set = new EmittedArtifactSet([
            new EmittedArtifact('a.yml', 'a', 'A'),
            new EmittedArtifact('b.yml', 'b', 'B'),
        ]);

        $this->assertCount(2, $set);
    }

    public function test_count_empty(): void
    {
        $set = new EmittedArtifactSet([]);
        $this->assertCount(0, $set);
    }

    public function test_by_path_finds_existing(): void
    {
        $set = new EmittedArtifactSet([
            new EmittedArtifact('a.yml', 'content-a', 'A'),
            new EmittedArtifact('b.yml', 'content-b', 'B'),
        ]);

        $found = $set->byPath('b.yml');
        $this->assertNotNull($found);
        $this->assertSame('content-b', $found->contents);
    }

    public function test_by_path_returns_null_for_missing(): void
    {
        $set = new EmittedArtifactSet([
            new EmittedArtifact('a.yml', 'content-a', 'A'),
        ]);

        $this->assertNull($set->byPath('nonexistent.yml'));
    }

    public function test_is_empty_true(): void
    {
        $this->assertTrue((new EmittedArtifactSet([]))->isEmpty());
    }

    public function test_is_empty_false(): void
    {
        $set = new EmittedArtifactSet([
            new EmittedArtifact('a.yml', 'a', 'A'),
        ]);

        $this->assertFalse($set->isEmpty());
    }

    public function test_iterable(): void
    {
        $set = new EmittedArtifactSet([
            new EmittedArtifact('a.yml', 'a', 'A'),
            new EmittedArtifact('b.yml', 'b', 'B'),
        ]);

        $paths = [];
        foreach ($set as $artifact) {
            $paths[] = $artifact->relativePath;
        }

        $this->assertSame(['a.yml', 'b.yml'], $paths);
    }
}
