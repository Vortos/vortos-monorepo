<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Vortos\OpsKit\Architecture\ProviderNameMatcher;

final class ProviderNameMatcherTest extends TestCase
{
    public function test_matches_provider_substring_in_a_segment(): void
    {
        $matcher = ProviderNameMatcher::default();
        $this->assertSame('caddy', $matcher->match('CaddyConfigService'));
        $this->assertSame('oracle', $matcher->match('App\\Infra\\OracleVpsTarget'));
    }

    public function test_is_case_insensitive(): void
    {
        $this->assertSame('github', ProviderNameMatcher::default()->match('GITHUB'));
    }

    public function test_returns_null_for_clean_symbols(): void
    {
        $matcher = ProviderNameMatcher::default();
        $this->assertNull($matcher->match('EdgeConfigService'));
        $this->assertNull($matcher->match('App\\Deploy\\DeployTarget'));
    }

    public function test_checks_each_namespace_segment(): void
    {
        $this->assertSame('prometheus', ProviderNameMatcher::default()->match('App\\Metrics\\Prometheus\\Foo'));
    }

    public function test_custom_provider_list_lowercased(): void
    {
        $matcher = new ProviderNameMatcher(['AcmeCloud']);
        $this->assertSame('acmecloud', $matcher->match('AcmeCloudTarget'));
        $this->assertNull($matcher->match('CaddyThing'), 'only the custom list applies');
    }

    public function test_empty_provider_token_never_matches(): void
    {
        $matcher = new ProviderNameMatcher(['']);
        $this->assertNull($matcher->match('Anything'));
    }
}
