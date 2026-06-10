<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Messaging\Definition\WireNaming;

final class WireNamingTest extends TestCase
{
    public function test_derives_module_and_snake_case_name(): void
    {
        $this->assertSame(
            'registration.entry_approved',
            WireNaming::derive('App\Registration\Domain\Entry\Event\EntryApproved'),
        );
    }

    public function test_derives_with_acronyms_and_digits(): void
    {
        $this->assertSame(
            'billing.vat_id_validated2',
            WireNaming::derive('App\Billing\Domain\Event\VATIdValidated2'),
        );
    }

    public function test_derives_single_segment_namespace(): void
    {
        $this->assertSame('app.thing_happened', WireNaming::derive('App\ThingHappened'));
    }

    public function test_format_appends_version_suffix(): void
    {
        $this->assertSame('registration.entry_approved.v2', WireNaming::format('registration.entry_approved', 2));
    }

    public function test_parse_extracts_name_and_version(): void
    {
        $this->assertSame(['registration.entry_approved', 2], WireNaming::parse('registration.entry_approved.v2'));
    }

    public function test_parse_without_suffix_defaults_to_v1(): void
    {
        $this->assertSame(['registration.entry_approved', 1], WireNaming::parse('registration.entry_approved'));
    }

    public function test_valid_names(): void
    {
        $this->assertTrue(WireNaming::isValidName('registration.entry_approved'));
        $this->assertTrue(WireNaming::isValidName('a.b.c_d'));
        $this->assertFalse(WireNaming::isValidName('Registration.EntryApproved'));
        $this->assertFalse(WireNaming::isValidName('no_dots'));
        $this->assertFalse(WireNaming::isValidName('App\\Class\\Name'));
    }
}
