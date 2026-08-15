<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\Headers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Security\Headers\ContentSecurityPolicyBuilder;

final class ContentSecurityPolicyBuilderTest extends TestCase
{
    public function test_standard_directives_compile_in_hyphenated_form(): void
    {
        $header = (new ContentSecurityPolicyBuilder([
            'default_src' => ["'none'"],
            'script_src'  => ["'self'", "'nonce-abc'"],
        ]))->headerValue();

        $this->assertStringContainsString("default-src 'none'", $header);
        $this->assertStringContainsString("script-src 'self' 'nonce-abc'", $header);
    }

    /**
     * The regression this class exists to prevent.
     *
     * Custom directives arrive as array KEYS, and Symfony's Config component
     * normalises keys by replacing hyphens with underscores — so a caller writing
     * `->directive('frame-ancestors', "'none'")` produced `frame_ancestors 'none'`
     * in the header. Browsers ignore an unknown directive rather than erroring, so
     * the policy looked right in review and the directive did nothing at all.
     */
    #[DataProvider('customDirectives')]
    public function test_custom_directive_names_are_emitted_hyphenated(string $configured, string $expected): void
    {
        $header = (new ContentSecurityPolicyBuilder([
            'default_src' => ["'none'"],
            'extra'       => [$configured => ["'none'"]],
        ]))->headerValue();

        $this->assertStringContainsString($expected . " 'none'", $header);
        $this->assertStringNotContainsString('_', $header, "no CSP directive name contains an underscore: {$header}");
    }

    /** @return iterable<string, array{string, string}> */
    public static function customDirectives(): iterable
    {
        // Both spellings must work: the caller may write either, and the config
        // layer may hand over either.
        yield 'underscored by config normalisation' => ['frame_ancestors', 'frame-ancestors'];
        yield 'hyphenated as written'               => ['frame-ancestors', 'frame-ancestors'];
        yield 'base-uri'                            => ['base_uri', 'base-uri'];
        yield 'form-action'                         => ['form_action', 'form-action'];
        yield 'upgrade-insecure-requests'           => ['upgrade_insecure_requests', 'upgrade-insecure-requests'];
    }

    public function test_empty_directives_are_omitted_rather_than_emitted_bare(): void
    {
        // `script-src` with no values is not "deny", it is a malformed directive.
        $header = (new ContentSecurityPolicyBuilder([
            'default_src' => ["'none'"],
            'script_src'  => [],
        ]))->headerValue();

        $this->assertStringNotContainsString('script-src', $header);
    }

    public function test_report_only_changes_the_header_name_only(): void
    {
        $config = ['default_src' => ["'none'"], 'report_only' => true];
        $builder = new ContentSecurityPolicyBuilder($config);

        $this->assertSame('Content-Security-Policy-Report-Only', $builder->headerName());
        $this->assertStringContainsString("default-src 'none'", $builder->headerValue());

        $enforcing = new ContentSecurityPolicyBuilder(['default_src' => ["'none'"], 'report_only' => false]);
        $this->assertSame('Content-Security-Policy', $enforcing->headerName());
    }

    public function test_report_targets_are_appended(): void
    {
        $header = (new ContentSecurityPolicyBuilder([
            'default_src' => ["'none'"],
            'report_uri'  => '/csp-report',
            'report_to'   => 'csp-endpoint',
        ]))->headerValue();

        $this->assertStringContainsString('report-uri /csp-report', $header);
        $this->assertStringContainsString('report-to csp-endpoint', $header);
    }
}
