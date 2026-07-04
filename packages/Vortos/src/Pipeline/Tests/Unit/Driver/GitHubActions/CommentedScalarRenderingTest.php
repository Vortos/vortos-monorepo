<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Driver\GitHubActions;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Driver\GitHubActions\WorkflowYamlWriter;
use Vortos\Pipeline\Driver\GitHubActions\Yaml\CommentedScalar;

/**
 * B1: a pinned `uses:` must render as `owner/repo@<sha>  # v4`, with the version an actual trailing
 * YAML comment — never folded into the (quoted) scalar, which made GitHub resolve a nonexistent
 * `<sha> # v4` ref and broke every generated workflow.
 */
final class CommentedScalarRenderingTest extends TestCase
{
    private WorkflowYamlWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new WorkflowYamlWriter();
    }

    public function test_comment_is_emitted_outside_the_scalar_as_a_real_comment(): void
    {
        $out = $this->writer->dump([
            'uses' => new CommentedScalar('actions/checkout@b4ffde65f46336ab88eb53be808477a3936bae11', 'v4'),
        ]);

        self::assertSame(
            "uses: actions/checkout@b4ffde65f46336ab88eb53be808477a3936bae11  # v4\n",
            $out,
        );
        // The ref before the comment must be a clean SHA pin (no stray quote folding the comment in).
        self::assertMatchesRegularExpression(
            '~^uses: [a-z0-9._/-]+@[0-9a-f]{40}\s+# v4\n$~i',
            $out,
        );
    }

    public function test_comment_survives_inside_a_step_list(): void
    {
        $out = $this->writer->dump([
            'steps' => [
                ['name' => 'Checkout', 'uses' => new CommentedScalar('actions/checkout@b4ffde65f46336ab88eb53be808477a3936bae11', 'v4')],
            ],
        ]);

        self::assertStringContainsString(
            'uses: actions/checkout@b4ffde65f46336ab88eb53be808477a3936bae11  # v4',
            $out,
        );
        self::assertStringNotContainsString("'actions/checkout", $out, 'Ref must not be quoted with the comment inside.');
    }

    public function test_empty_comment_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CommentedScalar('actions/checkout@sha', '');
    }

    public function test_multiline_comment_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CommentedScalar('actions/checkout@sha', "v4\nrm -rf /");
    }
}
