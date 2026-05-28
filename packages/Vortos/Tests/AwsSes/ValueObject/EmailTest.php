<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\ValueObject;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\ValueObject\Attachment;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\EmailAddress;

final class EmailTest extends TestCase
{
    public function test_valid_email_constructed(): void
    {
        $email = Email::new()
            ->to('user@example.com')
            ->subject('Hello')
            ->htmlBody('<p>Hi</p>');

        $this->assertCount(1, $email->getTo());
        $this->assertSame('Hello', $email->getSubject());
    }

    public function test_to_accepts_string(): void
    {
        $email = Email::new()->to('user@example.com')->subject('S')->htmlBody('H');
        $this->assertSame('user@example.com', $email->getTo()[0]->address());
    }

    public function test_to_accepts_email_address_object(): void
    {
        $addr  = new EmailAddress('user@example.com', 'Alice');
        $email = Email::new()->to($addr)->subject('S')->htmlBody('H');
        $this->assertSame('user@example.com', $email->getTo()[0]->address());
    }

    public function test_multiple_to_recipients(): void
    {
        $email = Email::new()
            ->to('a@example.com')
            ->to('b@example.com')
            ->subject('S')
            ->htmlBody('H');

        $this->assertCount(2, $email->getTo());
    }

    public function test_cc_and_bcc(): void
    {
        $email = Email::new()
            ->to('to@example.com')
            ->cc('cc@example.com')
            ->bcc('bcc@example.com')
            ->subject('S')
            ->htmlBody('H');

        $this->assertCount(1, $email->getCc());
        $this->assertCount(1, $email->getBcc());
    }

    public function test_get_all_recipients_combines_to_cc_bcc(): void
    {
        $email = Email::new()
            ->to('to@example.com')
            ->cc('cc@example.com')
            ->bcc('bcc@example.com')
            ->subject('S')
            ->htmlBody('H');

        $this->assertCount(3, $email->getAllRecipients());
    }

    public function test_from_set(): void
    {
        $email = Email::new()
            ->to('user@example.com')
            ->from('sender@example.com', 'Sender')
            ->subject('S')
            ->htmlBody('H');

        $this->assertSame('sender@example.com', $email->getFrom()->address());
        $this->assertSame('Sender', $email->getFrom()->name());
    }

    public function test_from_defaults_to_null(): void
    {
        $email = Email::new()->to('user@example.com')->subject('S')->htmlBody('H');
        $this->assertNull($email->getFrom());
    }

    public function test_reply_to_set(): void
    {
        $email = Email::new()
            ->to('user@example.com')
            ->replyTo('reply@example.com')
            ->subject('S')
            ->htmlBody('H');

        $this->assertSame('reply@example.com', $email->getReplyTo()->address());
    }

    public function test_text_body_only_is_valid(): void
    {
        $email = Email::new()
            ->to('user@example.com')
            ->subject('Hello')
            ->textBody('Plain text');

        $email->validate(); // must not throw
        $this->addToAssertionCount(1);
    }

    public function test_both_html_and_text_body(): void
    {
        $email = Email::new()
            ->to('user@example.com')
            ->subject('Hello')
            ->htmlBody('<p>Hi</p>')
            ->textBody('Hi');

        $this->assertSame('<p>Hi</p>', $email->getHtmlBody());
        $this->assertSame('Hi', $email->getTextBody());
    }

    public function test_validate_throws_when_no_recipient(): void
    {
        $email = Email::new()->subject('S')->htmlBody('H');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"to" recipient');
        $email->validate();
    }

    public function test_validate_throws_when_no_subject(): void
    {
        $email = Email::new()->to('user@example.com')->htmlBody('H');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('subject');
        $email->validate();
    }

    public function test_validate_throws_when_no_body(): void
    {
        $email = Email::new()->to('user@example.com')->subject('S');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('body');
        $email->validate();
    }

    public function test_custom_headers(): void
    {
        $email = Email::new()
            ->to('user@example.com')
            ->subject('S')
            ->htmlBody('H')
            ->header('X-Tenant', 'acme')
            ->header('X-Campaign', 'welcome');

        $this->assertSame(['X-Tenant' => 'acme', 'X-Campaign' => 'welcome'], $email->getHeaders());
    }

    public function test_attachment_added(): void
    {
        $attachment = Attachment::fromContent('file.pdf', 'application/pdf', 'content');

        $email = Email::new()
            ->to('user@example.com')
            ->subject('S')
            ->htmlBody('H')
            ->attach($attachment);

        $this->assertCount(1, $email->getAttachments());
        $this->assertTrue($email->hasAttachment('file.pdf'));
        $this->assertFalse($email->hasAttachment('other.pdf'));
    }

    public function test_metadata_via_with_meta_returns_clone(): void
    {
        $original = Email::new()->to('user@example.com')->subject('S')->htmlBody('H');
        $withMeta = $original->withMeta('client_token', 'abc-123');

        $this->assertNull($original->getMeta('client_token'));
        $this->assertSame('abc-123', $withMeta->getMeta('client_token'));
    }

    public function test_get_meta_returns_default_when_missing(): void
    {
        $email = Email::new()->to('user@example.com')->subject('S')->htmlBody('H');
        $this->assertSame('default', $email->getMeta('missing', 'default'));
    }

    public function test_fluent_methods_return_same_instance(): void
    {
        $email = Email::new();
        $this->assertSame($email, $email->to('a@example.com'));
        $this->assertSame($email, $email->subject('S'));
        $this->assertSame($email, $email->htmlBody('H'));
        $this->assertSame($email, $email->textBody('T'));
        $this->assertSame($email, $email->header('X-Test', 'v'));
    }

    public function test_with_filtered_recipients_removes_specified_to_address(): void
    {
        $email = Email::new()
            ->to('keep@example.com')
            ->to('remove@example.com')
            ->subject('S')->htmlBody('H');

        $filtered = $email->withFilteredRecipients(['remove@example.com']);

        $tos = array_map(fn($a) => $a->address(), $filtered->getTo());
        $this->assertContains('keep@example.com', $tos);
        $this->assertNotContains('remove@example.com', $tos);
    }

    public function test_with_filtered_recipients_is_case_insensitive(): void
    {
        $email = Email::new()
            ->to('User@Example.COM')
            ->subject('S')->htmlBody('H');

        $filtered = $email->withFilteredRecipients(['user@example.com']);
        $this->assertCount(0, $filtered->getTo());
    }

    public function test_with_filtered_recipients_returns_same_instance_when_empty_suppressed(): void
    {
        $email = Email::new()->to('u@example.com')->subject('S')->htmlBody('H');
        $this->assertSame($email, $email->withFilteredRecipients([]));
    }

    public function test_with_filtered_recipients_does_not_mutate_original(): void
    {
        $email = Email::new()
            ->to('a@example.com')
            ->to('b@example.com')
            ->subject('S')->htmlBody('H');

        $email->withFilteredRecipients(['a@example.com']);

        $this->assertCount(2, $email->getTo());
    }

    public function test_with_filtered_recipients_filters_cc_and_bcc(): void
    {
        $email = Email::new()
            ->to('to@example.com')
            ->cc('cc@example.com')
            ->bcc('bcc@example.com')
            ->subject('S')->htmlBody('H');

        $filtered = $email->withFilteredRecipients(['cc@example.com', 'bcc@example.com']);

        $this->assertCount(1, $filtered->getTo());
        $this->assertCount(0, $filtered->getCc());
        $this->assertCount(0, $filtered->getBcc());
    }
}
