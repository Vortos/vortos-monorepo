<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Driver\GitHubActions;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Driver\GitHubActions\WorkflowYamlWriter;

final class WorkflowYamlWriterTest extends TestCase
{
    private WorkflowYamlWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new WorkflowYamlWriter();
    }

    public function test_simple_map(): void
    {
        $result = $this->writer->dump(['name' => 'CI']);
        $this->assertSame("name: CI\n", $result);
    }

    public function test_nested_maps_indent_correctly(): void
    {
        $result = $this->writer->dump([
            'jobs' => [
                'tests' => [
                    'runs-on' => 'ubuntu-latest',
                ],
            ],
        ]);

        $this->assertStringContainsString("jobs:\n", $result);
        $this->assertStringContainsString("  tests:\n", $result);
        $this->assertStringContainsString("    runs-on: ubuntu-latest\n", $result);
    }

    public function test_list_of_scalars(): void
    {
        $result = $this->writer->dump([
            'branches' => ['main', 'develop'],
        ]);

        $this->assertStringContainsString("branches:\n", $result);
        $this->assertStringContainsString("  - main\n", $result);
        $this->assertStringContainsString("  - develop\n", $result);
    }

    public function test_list_of_maps(): void
    {
        $result = $this->writer->dump([
            'steps' => [
                ['name' => 'Checkout', 'uses' => 'actions/checkout@abc'],
                ['name' => 'Build', 'run' => 'make build'],
            ],
        ]);

        $this->assertStringContainsString("steps:\n", $result);
        $this->assertStringContainsString("  - name: Checkout\n", $result);
        $this->assertStringContainsString("    uses: 'actions/checkout@abc'\n", $result);
        $this->assertStringContainsString("  - name: Build\n", $result);
        $this->assertStringContainsString("    run: make build\n", $result);
    }

    public function test_multiline_string_uses_block_scalar(): void
    {
        $result = $this->writer->dump([
            'run' => "line1\nline2\nline3",
        ]);

        $this->assertStringContainsString("run: |\n", $result);
        $this->assertStringContainsString("  line1\n", $result);
        $this->assertStringContainsString("  line2\n", $result);
        $this->assertStringContainsString("  line3\n", $result);
    }

    public function test_boolean_values(): void
    {
        $result = $this->writer->dump([
            'enabled' => true,
            'disabled' => false,
        ]);

        $this->assertStringContainsString("enabled: true\n", $result);
        $this->assertStringContainsString("disabled: false\n", $result);
    }

    public function test_null_value(): void
    {
        $result = $this->writer->dump([
            'value' => null,
        ]);

        $this->assertStringContainsString("value: null\n", $result);
    }

    public function test_empty_string_is_quoted(): void
    {
        $result = $this->writer->dump([
            'empty' => '',
        ]);

        $this->assertStringContainsString("empty: ''\n", $result);
    }

    public function test_strings_with_colon_are_quoted(): void
    {
        $result = $this->writer->dump([
            'value' => 'key: value',
        ]);

        $this->assertStringContainsString("value: 'key: value'\n", $result);
    }

    public function test_strings_with_hash_are_quoted(): void
    {
        $result = $this->writer->dump([
            'value' => 'some # comment',
        ]);

        $this->assertStringContainsString("value: 'some # comment'\n", $result);
    }

    public function test_on_key_is_quoted(): void
    {
        $result = $this->writer->dump([
            'on' => ['push' => null],
        ]);

        $this->assertStringContainsString("'on':\n", $result);
    }

    public function test_true_key_is_quoted(): void
    {
        $result = $this->writer->dump([
            'true' => 'value',
        ]);

        $this->assertStringContainsString("'true': value\n", $result);
    }

    public function test_false_key_is_quoted(): void
    {
        $result = $this->writer->dump([
            'false' => 'value',
        ]);

        $this->assertStringContainsString("'false': value\n", $result);
    }

    public function test_empty_map_renders_as_braces(): void
    {
        $result = $this->writer->dump([
            'empty' => [],
        ]);

        $this->assertStringContainsString("empty: {}\n", $result);
    }

    public function test_byte_stability(): void
    {
        $data = [
            'name' => 'CI',
            'jobs' => [
                'test' => [
                    'runs-on' => 'ubuntu-latest',
                    'steps' => [
                        ['name' => 'Checkout', 'uses' => 'actions/checkout@abc'],
                    ],
                ],
            ],
        ];

        $first = $this->writer->dump($data);
        $second = $this->writer->dump($data);

        $this->assertSame($first, $second, 'Same input must produce identical output');
    }

    public function test_numeric_looking_strings_are_quoted(): void
    {
        $result = $this->writer->dump([
            'version' => '8.5',
        ]);

        $this->assertStringContainsString("version: '8.5'\n", $result);
    }

    public function test_integer_value_not_quoted(): void
    {
        $result = $this->writer->dump([
            'timeout' => 30,
        ]);

        $this->assertStringContainsString("timeout: 30\n", $result);
    }

    public function test_boolean_string_values_are_quoted(): void
    {
        $result = $this->writer->dump([
            'value' => 'true',
        ]);

        $this->assertStringContainsString("value: 'true'\n", $result);
    }

    public function test_null_string_value_is_quoted(): void
    {
        $result = $this->writer->dump([
            'value' => 'null',
        ]);

        $this->assertStringContainsString("value: 'null'\n", $result);
    }

    public function test_unsupported_scalar_type_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->writer->dump(['value' => new \stdClass()]);
    }
}
