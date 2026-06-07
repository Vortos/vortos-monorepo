<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Tests\Middleware;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Contract\SuppressionListInterface;
use Vortos\AwsSes\Exception\SuppressionListException;
use Vortos\AwsSes\Middleware\SuppressionCheckMiddleware;
use Vortos\AwsSes\Suppression\OnSuppressed;
use Vortos\AwsSes\Suppression\SuppressionEntry;
use Vortos\AwsSes\Suppression\SuppressionReason;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\EmailAddress;
use Vortos\AwsSes\ValueObject\SentEmail;

final class SuppressionCheckMiddlewareTest extends TestCase
{
    private function makeSentEmail(): SentEmail
    {
        return new SentEmail('msg-1', new \DateTimeImmutable(), 1, 'log', null);
    }

    private function makeEmail(string ...$toAddresses): Email
    {
        $email = Email::new()->subject('S')->htmlBody('H');
        foreach ($toAddresses as $addr) {
            $email = $email->to($addr);
        }
        return $email;
    }

    private function makeList(array $suppressed): SuppressionListInterface
    {
        return new class($suppressed) implements SuppressionListInterface {
            public function __construct(private array $suppressed) {}

            public function isSuppressed(EmailAddress $address): bool
            {
                return in_array(strtolower($address->address()), $this->suppressed, true);
            }

            public function suppress(EmailAddress $address, SuppressionReason $reason): void {}
            public function unsuppress(EmailAddress $address): void {}
            public function list(int $limit = 100, int $offset = 0): array { return []; }
        };
    }

    public function test_passes_through_when_no_suppressed_recipients(): void
    {
        $list  = $this->makeList([]);
        $mw    = new SuppressionCheckMiddleware($list, OnSuppressed::Throw);
        $email = $this->makeEmail('clean@example.com');
        $sent  = $this->makeSentEmail();

        $result = $mw->process($email, fn($e) => $sent);

        $this->assertSame($sent, $result);
    }

    public function test_throw_mode_raises_exception_for_suppressed_recipient(): void
    {
        $list  = $this->makeList(['blocked@example.com']);
        $mw    = new SuppressionCheckMiddleware($list, OnSuppressed::Throw);
        $email = $this->makeEmail('blocked@example.com');

        $this->expectException(SuppressionListException::class);
        $mw->process($email, fn($e) => $this->makeSentEmail());
    }

    public function test_throw_mode_exception_message_contains_address(): void
    {
        $list  = $this->makeList(['blocked@example.com']);
        $mw    = new SuppressionCheckMiddleware($list, OnSuppressed::Throw);
        $email = $this->makeEmail('blocked@example.com');

        try {
            $mw->process($email, fn($e) => $this->makeSentEmail());
            $this->fail('Expected SuppressionListException');
        } catch (SuppressionListException $e) {
            $this->assertStringContainsString('blocked@example.com', $e->getMessage());
        }
    }

    public function test_ignore_mode_bypasses_suppression_check(): void
    {
        $list  = $this->makeList(['suppressed@example.com']);
        $mw    = new SuppressionCheckMiddleware($list, OnSuppressed::Ignore);
        $email = $this->makeEmail('suppressed@example.com');
        $sent  = $this->makeSentEmail();

        $result = $mw->process($email, fn($e) => $sent);

        $this->assertSame($sent, $result);
    }

    public function test_ignore_mode_does_not_consult_suppression_list(): void
    {
        $consulted = false;
        $list = new class($consulted) implements SuppressionListInterface {
            public function __construct(public bool &$consulted) {}
            public function isSuppressed(EmailAddress $address): bool { $this->consulted = true; return false; }
            public function suppress(EmailAddress $address, SuppressionReason $reason): void {}
            public function unsuppress(EmailAddress $address): void {}
            public function list(int $limit = 100, int $offset = 0): array { return []; }
        };

        $mw    = new SuppressionCheckMiddleware($list, OnSuppressed::Ignore);
        $email = $this->makeEmail('u@example.com');

        $mw->process($email, fn($e) => $this->makeSentEmail());

        $this->assertFalse($consulted);
    }

    public function test_skip_mode_removes_suppressed_recipient_and_sends(): void
    {
        $list     = $this->makeList(['blocked@example.com']);
        $mw       = new SuppressionCheckMiddleware($list, OnSuppressed::Skip);
        $email    = Email::new()->subject('S')->htmlBody('H')
            ->to('clean@example.com')
            ->to('blocked@example.com');

        $receivedEmail = null;
        $sent = $this->makeSentEmail();

        $mw->process($email, function (Email $e) use (&$receivedEmail, $sent) {
            $receivedEmail = $e;
            return $sent;
        });

        $tos = array_map(fn($a) => $a->address(), $receivedEmail->getTo());
        $this->assertContains('clean@example.com', $tos);
        $this->assertNotContains('blocked@example.com', $tos);
    }

    public function test_skip_mode_throws_when_all_recipients_suppressed(): void
    {
        $list  = $this->makeList(['only@example.com']);
        $mw    = new SuppressionCheckMiddleware($list, OnSuppressed::Skip);
        $email = $this->makeEmail('only@example.com');

        $this->expectException(SuppressionListException::class);
        $mw->process($email, fn($e) => $this->makeSentEmail());
    }

    public function test_checks_cc_and_bcc_recipients_in_throw_mode(): void
    {
        $list  = $this->makeList(['cc@example.com']);
        $mw    = new SuppressionCheckMiddleware($list, OnSuppressed::Throw);
        $email = Email::new()->to('to@example.com')->cc('cc@example.com')->subject('S')->htmlBody('H');

        $this->expectException(SuppressionListException::class);
        $mw->process($email, fn($e) => $this->makeSentEmail());
    }
}
