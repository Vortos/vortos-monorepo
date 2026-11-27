<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Driver\GitHubActions;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Driver\GitHubActions\ExpressionLengthLimit;
use Vortos\Pipeline\Driver\GitHubActions\WorkflowYamlWriter;
use Vortos\Pipeline\Driver\GitHubActions\Yaml\CommentedScalar;
use Vortos\Pipeline\Exception\InvalidPipelineException;

final class ExpressionLengthLimitTest extends TestCase
{
    public function test_a_string_without_an_expression_has_no_limit(): void
    {
        self::assertSame(0, ExpressionLengthLimit::renderedLength(str_repeat("echo '{x}'\n", 5000)));
        ExpressionLengthLimit::assertWithin(['jobs' => ['a' => ['run' => str_repeat('x', 50000)]]]);
        $this->addToAssertionCount(1);
    }

    public function test_it_measures_the_format_call_github_evaluates(): void
    {
        // format('a {0} ''b'' {{c}}', github.sha)
        self::assertSame(
            strlen("format('a {0} ''b'' {{c}}', github.sha)"),
            ExpressionLengthLimit::renderedLength("a \${{ github.sha }} 'b' {c}"),
        );
    }

    public function test_a_long_run_block_with_expressions_is_refused_with_its_path(): void
    {
        $run = str_repeat("docker run '\${{ needs.build.outputs.image }}' {{.Id}}\n", 500);
        self::assertGreaterThan(ExpressionLengthLimit::GITHUB_LIMIT, ExpressionLengthLimit::renderedLength($run));

        $this->expectException(InvalidPipelineException::class);
        $this->expectExceptionMessageMatches('/jobs\.deploy\.steps\.1\.run renders to \d+ characters/');
        ExpressionLengthLimit::assertWithin(['jobs' => ['deploy' => ['steps' => [['run' => 'ok'], ['run' => $run]]]]]);
    }

    public function test_the_margin_is_below_githubs_limit(): void
    {
        self::assertLessThan(ExpressionLengthLimit::GITHUB_LIMIT, ExpressionLengthLimit::MAX);
        $atMax = '${{ a }}' . str_repeat('x', ExpressionLengthLimit::MAX - strlen("format('{0}', a)"));
        self::assertSame(ExpressionLengthLimit::MAX, ExpressionLengthLimit::renderedLength($atMax));
        ExpressionLengthLimit::assertWithin(['run' => $atMax]);

        $this->expectException(InvalidPipelineException::class);
        ExpressionLengthLimit::assertWithin(['run' => $atMax . 'x']);
    }

    public function test_the_workflow_writer_refuses_it_so_no_workflow_github_would_refuse_is_written(): void
    {
        $this->expectException(InvalidPipelineException::class);
        (new WorkflowYamlWriter())->dump(['jobs' => ['a' => ['steps' => [['uses' => new CommentedScalar(str_repeat('${{ x }}', 3000), 'v1')]]]]]);
    }
}
