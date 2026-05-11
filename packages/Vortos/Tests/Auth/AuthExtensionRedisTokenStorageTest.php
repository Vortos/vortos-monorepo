<?php

declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Auth\Contract\TokenStorageInterface;
use Vortos\Auth\DependencyInjection\AuthExtension;
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
        ->secret('test-secret-at-least-32-characters-long')
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
