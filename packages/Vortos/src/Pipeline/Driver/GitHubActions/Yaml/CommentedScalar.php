<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Driver\GitHubActions\Yaml;

/**
 * A scalar map value that carries a trailing YAML comment — emitted as
 * `key: <value>  # <comment>`, with the comment **outside** the (possibly quoted) scalar.
 *
 * This exists to pin GitHub Actions correctly. A pinned action must render as
 * `uses: owner/repo@<40-hex-sha>  # v4` where `# v4` is a genuine YAML comment. Folding the
 * comment into the scalar (`uses: 'owner/repo@<sha> # v4'`) makes GitHub try to resolve the ref
 * `<sha> # v4`, which does not exist — every generated workflow becomes unrunnable. Representing
 * the comment structurally lets {@see \Vortos\Pipeline\Driver\GitHubActions\WorkflowYamlWriter}
 * quote the value as needed and always emit the comment as a real comment.
 */
final readonly class CommentedScalar
{
    public function __construct(
        public string $value,
        public string $comment,
    ) {
        if ($comment === '') {
            throw new \InvalidArgumentException('CommentedScalar comment must be non-empty.');
        }

        // A comment can never contain a line break — that would terminate the line early and
        // corrupt the document. Fail closed at construction rather than emit broken YAML.
        if (preg_match('/[\r\n]/', $comment) === 1) {
            throw new \InvalidArgumentException('CommentedScalar comment must be a single line (no CR/LF).');
        }
    }
}
