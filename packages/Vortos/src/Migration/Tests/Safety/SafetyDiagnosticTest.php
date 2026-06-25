<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Safety;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Safety\SafetyDiagnostic;
use Vortos\Migration\Safety\Severity;

final class SafetyDiagnosticTest extends TestCase
{
    public function test_excerpt_is_truncated_to_200_chars(): void
    {
        $longSql = str_repeat('A', 300);
        $diag = new SafetyDiagnostic('pg.test', Severity::Error, null, $longSql, 'msg', 'fix');

        $this->assertLessThanOrEqual(204, strlen($diag->statementExcerpt));
        $this->assertStringEndsWith('…', $diag->statementExcerpt);
    }

    public function test_control_characters_are_stripped(): void
    {
        $sql = "CREATE\x00INDEX\x1B\x07 idx";
        $diag = new SafetyDiagnostic('pg.test', Severity::Error, null, $sql, 'msg', 'fix');

        $this->assertStringNotContainsString("\x00", $diag->statementExcerpt);
        $this->assertStringNotContainsString("\x1B", $diag->statementExcerpt);
    }

    public function test_long_string_literals_are_elided(): void
    {
        $sql = "INSERT INTO users VALUES ('this_is_a_very_long_literal_string_value_to_be_elided')";
        $diag = new SafetyDiagnostic('pg.test', Severity::Error, null, $sql, 'msg', 'fix');

        $this->assertStringContainsString("'…'", $diag->statementExcerpt);
    }

    public function test_short_string_literals_are_not_elided(): void
    {
        $sql = "INSERT INTO users VALUES ('hi')";
        $diag = new SafetyDiagnostic('pg.test', Severity::Error, null, $sql, 'msg', 'fix');

        $this->assertStringContainsString("'hi'", $diag->statementExcerpt);
    }

    public function test_to_array_has_sorted_keys(): void
    {
        $diag = new SafetyDiagnostic('pg.test', Severity::Warning, 'users', 'SELECT 1', 'msg', 'fix', 'SomeAttr');
        $arr = $diag->toArray();

        $keys = array_keys($arr);
        $sorted = $keys;
        sort($sorted);

        $this->assertSame($sorted, $keys);
    }

    public function test_to_array_contains_all_fields(): void
    {
        $diag = new SafetyDiagnostic('pg.test', Severity::Error, 'orders', 'ALTER TABLE', 'msg', 'fix', 'OptOut');
        $arr = $diag->toArray();

        $this->assertSame('pg.test', $arr['ruleId']);
        $this->assertSame('error', $arr['severity']);
        $this->assertSame('orders', $arr['table']);
        $this->assertSame('msg', $arr['message']);
        $this->assertSame('fix', $arr['remediation']);
        $this->assertSame('OptOut', $arr['optOutAttribute']);
    }
}
