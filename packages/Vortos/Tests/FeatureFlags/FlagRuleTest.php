<?php

declare(strict_types=1);

namespace Vortos\Tests\FeatureFlags;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FlagRule;

final class FlagRuleTest extends TestCase
{
    public function test_users_rule_roundtrip(): void
    {
        $rule = new FlagRule(FlagRule::TYPE_USERS, users: ['user-1', 'user-2']);
        $this->assertEquals($rule, FlagRule::fromArray($rule->toArray()));
    }

    public function test_percentage_rule_roundtrip(): void
    {
        $rule = new FlagRule(FlagRule::TYPE_PERCENTAGE, percentage: 42);
        $this->assertEquals($rule, FlagRule::fromArray($rule->toArray()));
    }

    public function test_attribute_equals_roundtrip(): void
    {
        $rule = new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'plan', operator: FlagRule::OP_EQUALS, value: 'enterprise');
        $this->assertEquals($rule, FlagRule::fromArray($rule->toArray()));
    }

    public function test_attribute_in_roundtrip(): void
    {
        $rule = new FlagRule(FlagRule::TYPE_ATTRIBUTE, attribute: 'region', operator: FlagRule::OP_IN, value: ['eu-west', 'eu-central']);
        $this->assertEquals($rule, FlagRule::fromArray($rule->toArray()));
    }

    public function test_to_array_excludes_null_fields(): void
    {
        $rule = new FlagRule(FlagRule::TYPE_PERCENTAGE, percentage: 25);
        $arr  = $rule->toArray();

        $this->assertArrayNotHasKey('attribute', $arr);
        $this->assertArrayNotHasKey('operator', $arr);
        $this->assertArrayNotHasKey('value', $arr);
    }
}
