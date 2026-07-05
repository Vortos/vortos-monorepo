<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Parameter;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Auth\Contract\TokenStorageInterface;
use Vortos\Auth\DependencyInjection\AuthExtension;
use Vortos\Auth\Lockout\LockoutManager;
use Vortos\Auth\Storage\RedisTokenStorage;

final class AuthExtensionRedisTokenStorageTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/vortos_auth_redis_test_' . uniqid();
        mkdir($this->tmpDir . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function test_redis_token_storage_is_registered_when_redis_service_already_exists(): void
    {
        $this->writeAuthConfig();

        $container = $this->makeContainer();
        $container->register(\Redis::class, \stdClass::class);

        (new AuthExtension())->load([], $container);

        $this->assertTrue($container->hasDefinition(RedisTokenStorage::class));
        $this->assertSame(RedisTokenStorage::class, (string) $container->getAlias(TokenStorageInterface::class));
    }

    /**
     * B21/B22 regression: the Redis-enabled branch (LockoutManager, RateLimit, Quota, Session, ApiKey,
     * Audit) registers many services from config-derived objects. Every service argument must be
     * dumpable — a raw object instance breaks Symfony's PhpDumper and the prod HTTP boot. This walks
     * the registered definitions with the same rule the framework's ContainerDumpabilityPass enforces,
     * so a re-introduced object argument (like the LockoutConfig that slipped past the first audit) is
     * caught here rather than at a production boot. Self-contained — no dependency on vortos-foundation.
     */
    public function test_redis_branch_registers_only_dumpable_service_arguments(): void
    {
        $this->writeAuthConfig();

        $container = $this->makeContainer('prod');
        $container->register(\Redis::class, \stdClass::class);

        (new AuthExtension())->load([], $container);

        // Sanity: the branch under audit is actually active.
        $this->assertTrue(
            $container->hasDefinition(LockoutManager::class),
            'the Redis branch (where B22 lived) must be registered for this regression to mean anything',
        );

        $offenders = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            $this->collectNonDumpable($id, $definition, $offenders);
        }

        $this->assertSame([], $offenders, "Non-dumpable service arguments (B21/B22 class):\n  - " . implode("\n  - ", $offenders));
    }

    /** @param list<string> $offenders */
    private function collectNonDumpable(string $id, Definition $definition, array &$offenders): void
    {
        foreach ($definition->getArguments() as $key => $arg) {
            $this->scanValue($id, "[$key]", $arg, $offenders);
        }
        foreach ($definition->getProperties() as $name => $value) {
            $this->scanValue($id, "\$$name", $value, $offenders);
        }
        foreach ($definition->getMethodCalls() as $call) {
            foreach (($call[1] ?? []) as $key => $arg) {
                $this->scanValue($id, ($call[0] ?? '?') . "()[$key]", $arg, $offenders);
            }
        }
    }

    /** @param list<string> $offenders */
    private function scanValue(string $id, string $path, mixed $value, array &$offenders): void
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $this->scanValue($id, "$path/$k", $v, $offenders);
            }

            return;
        }

        if (!is_object($value)) {
            return;
        }

        if ($value instanceof Definition) {
            // Inline definitions are dumpable, but their own arguments must be too.
            $this->collectNonDumpable($id . ' → inline', $value, $offenders);

            return;
        }

        if ($value instanceof Reference
            || $value instanceof Parameter
            || $value instanceof ArgumentInterface
            || $value instanceof \UnitEnum) {
            return;
        }

        $offenders[] = sprintf('%s %s: %s', $id, $path, $value::class);
    }

    private function makeContainer(string $env = 'test'): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.env', $env);
        $container->setParameter('kernel.project_dir', $this->tmpDir);

        return $container;
    }

    private function writeAuthConfig(): void
    {
        $content = <<<'PHP'
<?php
use Vortos\Auth\DependencyInjection\VortosAuthConfig;
use Vortos\Auth\Storage\RedisTokenStorage;

return static function (VortosAuthConfig $config): void {
    $config
        ->hs256('test-secret-at-least-sixty-four-characters-long-for-hs256-rotation')
        ->tokenStorage(RedisTokenStorage::class);
};
PHP;
        file_put_contents($this->tmpDir . '/config/auth.php', $content);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }
}
