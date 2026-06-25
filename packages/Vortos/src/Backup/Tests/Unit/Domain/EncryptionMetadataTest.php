<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\EncryptionMetadata;

final class EncryptionMetadataTest extends TestCase
{
    public function test_to_array(): void
    {
        $meta = new EncryptionMetadata('age', 'default', 0x01);

        $arr = $meta->toArray();
        $this->assertSame('age', $arr['provider']);
        $this->assertSame('default', $arr['recipient_id']);
        $this->assertSame(1, $arr['aead_id']);
    }
}
