<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\Driver;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\OpsKit\Driver\Exception\UnknownDriverException;
use Vortos\OpsKit\Tests\Fixtures\Demo\GreeterRegistry;
use Vortos\OpsKit\Tests\Fixtures\Demo\LoudGreeter;
use Vortos\OpsKit\Tests\Fixtures\Demo\QuietGreeter;

final class TaggedDriverRegistryTest extends TestCase
{
    public function test_get_resolves_by_key(): void
    {
        $registry = $this->registry();
        $this->assertInstanceOf(LoudGreeter::class, $registry->get('loud'));
        $this->assertInstanceOf(QuietGreeter::class, $registry->greeter('quiet'));
    }

    public function test_has_reports_presence(): void
    {
        $registry = $this->registry();
        $this->assertTrue($registry->has('loud'));
        $this->assertFalse($registry->has('nope'));
    }

    public function test_keys_are_sorted(): void
    {
        $this->assertSame(['loud', 'quiet'], $this->registry()->keys());
    }

    public function test_unknown_key_throws_naming_available_keys(): void
    {
        $registry = $this->registry();

        try {
            $registry->get('caddy');
            $this->fail('Expected UnknownDriverException');
        } catch (UnknownDriverException $e) {
            $this->assertStringContainsString("'caddy'", $e->getMessage());
            $this->assertStringContainsString('loud', $e->getMessage());
            $this->assertStringContainsString('quiet', $e->getMessage());
            $this->assertStringContainsString('greeter', $e->getMessage());
        }
    }

    public function test_drivers_are_lazy(): void
    {
        $instantiated = [];
        $locator = new ServiceLocator([
            'loud' => function () use (&$instantiated): LoudGreeter {
                $instantiated[] = 'loud';
                return new LoudGreeter();
            },
            'quiet' => function () use (&$instantiated): QuietGreeter {
                $instantiated[] = 'quiet';
                return new QuietGreeter();
            },
        ]);
        $registry = new GreeterRegistry($locator);

        $this->assertSame([], $instantiated, 'no driver should be built before it is requested');
        $registry->get('loud');
        $this->assertSame(['loud'], $instantiated, 'only the requested driver should be built');
    }

    public function test_empty_registry_has_no_keys(): void
    {
        $registry = new GreeterRegistry(new ServiceLocator([]));
        $this->assertSame([], $registry->keys());
        $this->assertFalse($registry->has('loud'));
    }

    private function registry(): GreeterRegistry
    {
        return new GreeterRegistry(new ServiceLocator([
            'loud' => static fn (): LoudGreeter => new LoudGreeter(),
            'quiet' => static fn (): QuietGreeter => new QuietGreeter(),
        ]));
    }
}
