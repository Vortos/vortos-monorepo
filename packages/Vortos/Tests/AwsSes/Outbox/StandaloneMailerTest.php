<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Outbox;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Contract\MailerInterface;
use Vortos\AwsSes\Outbox\StandaloneMailer;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

final class StandaloneMailerTest extends TestCase
{
    public function test_opens_own_transaction_when_none_is_active(): void
    {
        $email = Email::new()->to('u@example.com')->subject('S')->htmlBody('H');
        $sent = new SentEmail('id', new \DateTimeImmutable(), 1, 'outbox');
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send')->with($email)->willReturn($sent);

        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($this->once())->method('transactional')
            ->willReturnCallback(static fn(callable $callback): mixed => $callback());

        $this->assertSame($sent, (new StandaloneMailer($connection, $mailer))->send($email));
    }

    public function test_reuses_existing_transaction_when_active(): void
    {
        $email = Email::new()->to('u@example.com')->subject('S')->htmlBody('H');
        $sent = new SentEmail('id', new \DateTimeImmutable(), 1, 'outbox');
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send')->willReturn($sent);

        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects($this->never())->method('transactional');

        $this->assertSame($sent, (new StandaloneMailer($connection, $mailer))->send($email));
    }
}
