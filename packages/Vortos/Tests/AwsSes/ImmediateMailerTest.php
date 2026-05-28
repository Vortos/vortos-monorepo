<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Contract\MailerInterface;
use Vortos\AwsSes\ImmediateMailer;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

final class ImmediateMailerTest extends TestCase
{
    public function test_delegates_directly_to_inner_mailer(): void
    {
        $email = Email::new()->to('u@example.com')->subject('S')->htmlBody('H');
        $sent = new SentEmail('id', new \DateTimeImmutable(), 1, 'ses');
        $inner = $this->createMock(MailerInterface::class);
        $inner->expects($this->once())->method('send')->with($email)->willReturn($sent);

        $this->assertSame($sent, (new ImmediateMailer($inner))->send($email));
    }
}
