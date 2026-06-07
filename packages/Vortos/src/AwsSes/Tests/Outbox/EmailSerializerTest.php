<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Tests\Outbox;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Outbox\EmailSerializer;
use Vortos\AwsSes\ValueObject\Attachment;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\EmailAddress;

final class EmailSerializerTest extends TestCase
{
    public function test_round_trip_basic_email(): void
    {
        $email = Email::new()
            ->to('user@example.com')
            ->subject('Hello')
            ->htmlBody('<p>Hi</p>');

        $restored = EmailSerializer::fromArray(EmailSerializer::toArray($email));

        $this->assertSame('Hello', $restored->getSubject());
        $this->assertSame('<p>Hi</p>', $restored->getHtmlBody());
        $this->assertCount(1, $restored->getTo());
        $this->assertSame('user@example.com', $restored->getTo()[0]->address());
    }

    public function test_round_trip_all_recipient_types(): void
    {
        $email = Email::new()
            ->to('to@example.com')
            ->cc('cc@example.com')
            ->bcc('bcc@example.com')
            ->subject('S')->htmlBody('H');

        $restored = EmailSerializer::fromArray(EmailSerializer::toArray($email));

        $this->assertSame('to@example.com',  $restored->getTo()[0]->address());
        $this->assertSame('cc@example.com',  $restored->getCc()[0]->address());
        $this->assertSame('bcc@example.com', $restored->getBcc()[0]->address());
    }

    public function test_round_trip_from_and_reply_to(): void
    {
        $email = Email::new()
            ->to('u@example.com')
            ->from('sender@example.com', 'Sender')
            ->replyTo('reply@example.com')
            ->subject('S')->htmlBody('H');

        $restored = EmailSerializer::fromArray(EmailSerializer::toArray($email));

        $this->assertSame('sender@example.com', $restored->getFrom()?->address());
        $this->assertSame('Sender',             $restored->getFrom()?->name());
        $this->assertSame('reply@example.com',  $restored->getReplyTo()?->address());
    }

    public function test_round_trip_text_and_html_body(): void
    {
        $email = Email::new()
            ->to('u@example.com')
            ->subject('S')
            ->htmlBody('<p>HTML</p>')
            ->textBody('Plain text');

        $restored = EmailSerializer::fromArray(EmailSerializer::toArray($email));

        $this->assertSame('<p>HTML</p>', $restored->getHtmlBody());
        $this->assertSame('Plain text', $restored->getTextBody());
    }

    public function test_round_trip_custom_headers(): void
    {
        $email = Email::new()
            ->to('u@example.com')
            ->subject('S')->htmlBody('H')
            ->header('X-Tenant', 'acme')
            ->header('X-Env', 'prod');

        $restored = EmailSerializer::fromArray(EmailSerializer::toArray($email));

        $this->assertSame('acme', $restored->getHeaders()['X-Tenant']);
        $this->assertSame('prod', $restored->getHeaders()['X-Env']);
    }

    public function test_round_trip_metadata(): void
    {
        $email = Email::new()
            ->to('u@example.com')
            ->subject('S')->htmlBody('H')
            ->withMeta('domain_event_id', 'evt-uuid-1');

        $restored = EmailSerializer::fromArray(EmailSerializer::toArray($email));

        $this->assertSame('evt-uuid-1', $restored->getMeta('domain_event_id'));
    }

    public function test_round_trip_attachment(): void
    {
        $email = Email::new()
            ->to('u@example.com')
            ->subject('S')->htmlBody('H')
            ->attach(Attachment::fromContent('report.pdf', 'application/pdf', 'pdf-data'));

        $restored = EmailSerializer::fromArray(EmailSerializer::toArray($email));

        $this->assertCount(1, $restored->getAttachments());
        $this->assertSame('report.pdf',       $restored->getAttachments()[0]->filename());
        $this->assertSame('application/pdf',  $restored->getAttachments()[0]->mimeType());
    }

    public function test_attachment_content_not_double_encoded(): void
    {
        $originalContent = 'raw pdf bytes';
        $email = Email::new()
            ->to('u@example.com')
            ->subject('S')->htmlBody('H')
            ->attach(Attachment::fromContent('f.pdf', 'application/pdf', $originalContent));

        $originalEncoded = $email->getAttachments()[0]->content(); // base64 encoded once

        $restored = EmailSerializer::fromArray(EmailSerializer::toArray($email));
        $restoredEncoded = $restored->getAttachments()[0]->content();

        $this->assertSame($originalEncoded, $restoredEncoded);
    }

    public function test_null_from_and_reply_to_preserved(): void
    {
        $email = Email::new()->to('u@example.com')->subject('S')->htmlBody('H');

        $restored = EmailSerializer::fromArray(EmailSerializer::toArray($email));

        $this->assertNull($restored->getFrom());
        $this->assertNull($restored->getReplyTo());
    }
}
