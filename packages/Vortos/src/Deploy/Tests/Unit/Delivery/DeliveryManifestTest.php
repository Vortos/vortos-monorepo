<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Delivery;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Delivery\DeliveryArtifact;
use Vortos\Deploy\Delivery\DeliveryManifest;

final class DeliveryManifestTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/vortos-delivery-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700, true);
        file_put_contents($this->tmp . '/.env.prod', 'APP_ENV=prod');
        file_put_contents($this->tmp . '/vortos-secrets.age', 'ciphertext');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp . '/.env.prod');
        @unlink($this->tmp . '/vortos-secrets.age');
        @rmdir($this->tmp);
    }

    private function artifact(DeliveryManifest $m, string $remote): ?DeliveryArtifact
    {
        foreach ($m->artifacts as $a) {
            if ($a->remoteRelativePath === $remote) {
                return $a;
            }
        }

        return null;
    }

    public function test_default_ships_the_age_store_group_readable_0640(): void
    {
        // B15: the store must be owner+group readable (0640), not owner-only (0600) — the one-shot
        // container runs as a different uid and reads the store via a group grant.
        $manifest = DeliveryManifest::default($this->tmp);

        $store = $this->artifact($manifest, 'vortos-secrets.age');
        self::assertNotNull($store);
        self::assertSame('0640', $store->mode);
    }

    public function test_default_ships_env_prod_group_readable_0640(): void
    {
        // GAP-A: the nested cutover `docker compose up` parses .env.prod as the image uid, so it must
        // be owner+group readable (0640), not owner-only (0600) — the one-shot reads it via a group
        // grant (docker run --group-add of the env file gid), exactly like the age store.
        $manifest = DeliveryManifest::default($this->tmp);

        $env = $this->artifact($manifest, '.env.prod');
        self::assertNotNull($env);
        self::assertSame('0640', $env->mode);
    }
}
