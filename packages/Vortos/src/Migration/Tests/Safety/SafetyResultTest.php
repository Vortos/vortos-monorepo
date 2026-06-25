<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Safety;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Safety\SafetyDiagnostic;
use Vortos\Migration\Safety\SafetyResult;
use Vortos\Migration\Safety\Severity;

final class SafetyResultTest extends TestCase
{
    public function test_clean_has_no_errors(): void
    {
        $result = SafetyResult::clean('pg-native');

        $this->assertFalse($result->hasErrors());
        $this->assertSame([], $result->errors());
    }

    public function test_has_errors_when_error_diagnostic_present(): void
    {
        $result = new SafetyResult('pg-native', [
            $this->diag(Severity::Error),
            $this->diag(Severity::Warning),
        ]);

        $this->assertTrue($result->hasErrors());
        $this->assertCount(1, $result->errors());
    }

    public function test_errors_filters_only_errors(): void
    {
        $result = new SafetyResult('pg-native', [
            $this->diag(Severity::Warning),
            $this->diag(Severity::Info),
            $this->diag(Severity::Error),
        ]);

        $errors = $result->errors();
        $this->assertCount(1, $errors);
        $this->assertSame(Severity::Error, $errors[0]->severity);
    }

    public function test_by_severity_groups_correctly(): void
    {
        $result = new SafetyResult('pg-native', [
            $this->diag(Severity::Error),
            $this->diag(Severity::Warning),
            $this->diag(Severity::Info),
        ]);

        $grouped = $result->bySeverity();
        $this->assertCount(1, $grouped['error']);
        $this->assertCount(1, $grouped['warning']);
        $this->assertCount(1, $grouped['info']);
    }

    public function test_to_array_is_canonically_sorted(): void
    {
        $result = new SafetyResult('pg-native', [
            $this->diag(Severity::Warning, 'pg.b'),
            $this->diag(Severity::Error, 'pg.a'),
        ]);

        $arr = $result->toArray();
        $this->assertSame('pg-native', $arr['engine']);
        $this->assertCount(2, $arr['diagnostics']);
        $this->assertSame('pg.a', $arr['diagnostics'][0]['ruleId']);
        $this->assertSame('pg.b', $arr['diagnostics'][1]['ruleId']);
    }

    public function test_to_array_is_stable_golden_vector(): void
    {
        $result = new SafetyResult('pg-native', [
            $this->diag(Severity::Error, 'pg.index.non-concurrent', 'users'),
        ]);

        $arr = $result->toArray();
        $expected = [
            'diagnostics' => [
                [
                    'message' => 'Test message',
                    'optOutAttribute' => null,
                    'remediation' => 'Fix it',
                    'ruleId' => 'pg.index.non-concurrent',
                    'severity' => 'error',
                    'statementExcerpt' => 'CREATE INDEX',
                    'table' => 'users',
                ],
            ],
            'engine' => 'pg-native',
        ];

        $this->assertSame($expected, $arr);
    }

    public function test_merge_combines_diagnostics(): void
    {
        $r1 = new SafetyResult('pg-native', [$this->diag(Severity::Error, 'rule.a')]);
        $r2 = new SafetyResult('pg-native', [$this->diag(Severity::Warning, 'rule.b')]);

        $merged = SafetyResult::merge('pg-native', $r1, $r2);
        $this->assertCount(2, $merged->diagnostics);
    }

    private function diag(Severity $severity, string $ruleId = 'pg.test', ?string $table = null): SafetyDiagnostic
    {
        return new SafetyDiagnostic(
            ruleId: $ruleId,
            severity: $severity,
            table: $table,
            statementExcerpt: 'CREATE INDEX',
            message: 'Test message',
            remediation: 'Fix it',
        );
    }
}
