<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Model\SplitPackage;

final class SplitPackageTest extends TestCase
{
    public function test_valid(): void
    {
        $package = new SplitPackage('packages/Vortos/src/Domain', 'vortos-domain');

        $this->assertSame('packages/Vortos/src/Domain', $package->localPath);
        $this->assertSame('vortos-domain', $package->splitRepository);
    }

    public function test_to_array(): void
    {
        $package = new SplitPackage('packages/Vortos/src/Domain', 'vortos-domain');

        $this->assertSame([
            'local_path' => 'packages/Vortos/src/Domain',
            'split_repository' => 'vortos-domain',
        ], $package->toArray());
    }

    public function test_empty_path_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SplitPackage('', 'vortos-domain');
    }

    public function test_empty_repo_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SplitPackage('packages/Vortos/src/Domain', '');
    }
}
