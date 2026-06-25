<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use Vortos\Analytics\Event\DistinctId;
use Vortos\Analytics\Privacy\ConsentDecision;
use Vortos\Analytics\Privacy\DenyAllConsentResolver;

final class DenyAllConsentResolverTest extends TestCase
{
    public function test_denies_any_distinct_id(): void
    {
        $resolver = new DenyAllConsentResolver();

        $this->assertSame(ConsentDecision::Denied, $resolver->resolve(new DistinctId('user-1')));
        $this->assertSame(ConsentDecision::Denied, $resolver->resolve(new DistinctId('anonymous-xyz')));
    }
}
