<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Version;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Release\Version\ConventionalCommitParser;

final class ConventionalCommitParserTest extends TestCase
{
    private ConventionalCommitParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ConventionalCommitParser();
    }

    #[DataProvider('conventionalMessages')]
    public function test_parse(
        string $message,
        string $expectedType,
        ?string $expectedScope,
        bool $expectedBreaking,
        string $expectedDesc,
    ): void {
        $commit = $this->parser->parse($message, 'abc1234');

        $this->assertSame($expectedType, $commit->type);
        $this->assertSame($expectedScope, $commit->scope);
        $this->assertSame($expectedBreaking, $commit->breaking);
        $this->assertSame($expectedDesc, $commit->description);
        $this->assertSame('abc1234', $commit->sha);
    }

    public static function conventionalMessages(): iterable
    {
        yield 'feat' => ['feat: add new feature', 'feat', null, false, 'add new feature'];
        yield 'fix' => ['fix: resolve crash', 'fix', null, false, 'resolve crash'];
        yield 'feat with scope' => ['feat(auth): add SSO', 'feat', 'auth', false, 'add SSO'];
        yield 'fix with scope' => ['fix(db): fix migration', 'fix', 'db', false, 'fix migration'];
        yield 'breaking bang' => ['feat!: remove API', 'feat', null, true, 'remove API'];
        yield 'breaking bang with scope' => ['feat(api)!: drop v1', 'feat', 'api', true, 'drop v1'];
        yield 'chore' => ['chore: update deps', 'chore', null, false, 'update deps'];
        yield 'docs' => ['docs: update readme', 'docs', null, false, 'update readme'];
        yield 'refactor' => ['refactor: simplify logic', 'refactor', null, false, 'simplify logic'];
        yield 'perf' => ['perf: optimize query', 'perf', null, false, 'optimize query'];
        yield 'test' => ['test: add unit tests', 'test', null, false, 'add unit tests'];
        yield 'revert' => ['revert: revert "feat: X"', 'revert', null, false, 'revert "feat: X"'];
    }

    public function test_breaking_footer(): void
    {
        $message = "feat: big change\n\nSome body text.\n\nBREAKING CHANGE: old API removed";
        $commit = $this->parser->parse($message, 'sha1');

        $this->assertTrue($commit->breaking);
        $this->assertSame('feat', $commit->type);
    }

    public function test_breaking_footer_with_dash(): void
    {
        $message = "feat: change\n\nBREAKING-CHANGE: removed endpoint";
        $commit = $this->parser->parse($message, 'sha2');

        $this->assertTrue($commit->breaking);
    }

    public function test_non_conventional_message(): void
    {
        $commit = $this->parser->parse('benchmark fix', 'sha3');

        $this->assertSame('other', $commit->type);
        $this->assertNull($commit->scope);
        $this->assertFalse($commit->breaking);
        $this->assertSame('benchmark fix', $commit->description);
    }

    public function test_merge_commit(): void
    {
        $commit = $this->parser->parse("Merge branch 'main' into feature", 'sha4');

        $this->assertSame('other', $commit->type);
        $this->assertFalse($commit->breaking);
    }

    public function test_empty_message(): void
    {
        $commit = $this->parser->parse('', 'sha5');

        $this->assertSame('other', $commit->type);
        $this->assertSame('', $commit->description);
    }

    public function test_crlf_normalized(): void
    {
        $commit = $this->parser->parse("feat: add thing\r\n\r\nBody text\r\n", 'sha6');

        $this->assertSame('feat', $commit->type);
        $this->assertSame('add thing', $commit->description);
    }

    public function test_multi_paragraph_body(): void
    {
        $message = "fix(core): resolve race\n\nFirst paragraph.\n\nSecond paragraph.\n\nBREAKING CHANGE: old behavior gone";
        $commit = $this->parser->parse($message, 'sha7');

        $this->assertTrue($commit->breaking);
        $this->assertStringContainsString('First paragraph', $commit->body);
        $this->assertStringContainsString('Second paragraph', $commit->body);
    }

    public function test_bump_level_mapping(): void
    {
        $feat = $this->parser->parse('feat: x', 'a');
        $fix = $this->parser->parse('fix: x', 'b');
        $breaking = $this->parser->parse('feat!: x', 'c');
        $chore = $this->parser->parse('chore: x', 'd');
        $perf = $this->parser->parse('perf: x', 'e');

        $this->assertSame('minor', $feat->toBumpLevel()->value);
        $this->assertSame('patch', $fix->toBumpLevel()->value);
        $this->assertSame('major', $breaking->toBumpLevel()->value);
        $this->assertSame('none', $chore->toBumpLevel()->value);
        $this->assertSame('patch', $perf->toBumpLevel()->value);
    }
}
