<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Exception\UnpinnedActionException;
use Vortos\Pipeline\Model\PinnedAction;

final class PinnedActionTest extends TestCase
{
    public function test_valid_40_hex_sha_accepted(): void
    {
        $action = new PinnedAction('actions', 'checkout', 'b4ffde65f46336ab88eb53be808477a3936bae11', 'v4');

        $this->assertSame('b4ffde65f46336ab88eb53be808477a3936bae11', $action->sha);
    }

    #[DataProvider('invalidShas')]
    public function test_invalid_sha_throws(string $sha): void
    {
        $this->expectException(UnpinnedActionException::class);

        new PinnedAction('actions', 'checkout', $sha, 'v4');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidShas(): iterable
    {
        yield 'floating tag' => ['v4'];
        yield 'short sha' => ['b4ffde65'];
        yield 'uppercase hex' => ['B4FFDE65F46336AB88EB53BE808477A3936BAE11'];
        yield 'empty' => [''];
        yield '39 chars' => ['b4ffde65f46336ab88eb53be808477a3936bae1'];
        yield '41 chars' => ['b4ffde65f46336ab88eb53be808477a3936bae111'];
        yield 'non-hex chars' => ['b4ffde65f46336ab88eb53be808477a3936bae1g'];
        yield 'main branch' => ['main'];
        yield 'mixed case' => ['b4ffde65f46336ab88eb53be808477a3936bAe11'];
    }

    public function test_to_uses_string(): void
    {
        $action = new PinnedAction('actions', 'checkout', 'b4ffde65f46336ab88eb53be808477a3936bae11', 'v4');

        $this->assertSame('actions/checkout@b4ffde65f46336ab88eb53be808477a3936bae11', $action->toUsesString());
    }

    public function test_to_commented_string(): void
    {
        $action = new PinnedAction('actions', 'checkout', 'b4ffde65f46336ab88eb53be808477a3936bae11', 'v4');

        $this->assertSame(
            'actions/checkout@b4ffde65f46336ab88eb53be808477a3936bae11 # v4',
            $action->toCommentedString(),
        );
    }

    public function test_to_array(): void
    {
        $action = new PinnedAction('actions', 'checkout', 'b4ffde65f46336ab88eb53be808477a3936bae11', 'v4');

        $this->assertSame([
            'owner' => 'actions',
            'repo' => 'checkout',
            'sha' => 'b4ffde65f46336ab88eb53be808477a3936bae11',
            'version' => 'v4',
        ], $action->toArray());
    }

    public function test_empty_owner_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PinnedAction('', 'checkout', 'b4ffde65f46336ab88eb53be808477a3936bae11', 'v4');
    }

    public function test_empty_repo_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PinnedAction('actions', '', 'b4ffde65f46336ab88eb53be808477a3936bae11', 'v4');
    }
}
