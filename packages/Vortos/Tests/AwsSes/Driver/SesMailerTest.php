<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Driver;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Driver\Ses\SesClientFactory;
use Vortos\AwsSes\Driver\Ses\SesMailer;
use Vortos\AwsSes\Exception\MailSendException;
use Vortos\AwsSes\Exception\RateLimitExceededException;
use Vortos\AwsSes\ValueObject\Attachment;
use Vortos\AwsSes\ValueObject\Email;

final class SesMailerTest extends TestCase
{
    private function makeMailer(MockHandler $handler): SesMailer
    {
        $client = new SesV2Client([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler'     => $handler,
            // Disable SDK-level retries so throttling/rate-limit tests don't re-consume the mock queue
            'retries'     => 0,
        ]);

        return new SesMailer(
            client: $client,
            region: 'us-east-1',
            defaultFromAddress: 'noreply@example.com',
            defaultFromName: 'My App',
            defaultReplyTo: null,
            configurationSet: null,
        );
    }

    public function test_successful_send_returns_sent_email(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result(['MessageId' => 'ses-msg-id-123']));

        $mailer = $this->makeMailer($handler);
        $email  = Email::new()->to('user@example.com')->subject('Hello')->htmlBody('<p>Hi</p>');

        $result = $mailer->send($email);

        $this->assertSame('ses-msg-id-123', $result->messageId());
        $this->assertSame('ses', $result->driver());
        $this->assertSame('us-east-1', $result->region());
    }

    public function test_uses_default_from_when_email_has_none(): void
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $this->assertSame('"My App" <noreply@example.com>', $cmd['FromEmailAddress']);
            return new Result(['MessageId' => 'id']);
        });

        $mailer = $this->makeMailer($handler);
        $email  = Email::new()->to('user@example.com')->subject('S')->htmlBody('H');
        $mailer->send($email);
    }

    public function test_uses_email_from_when_set(): void
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $this->assertSame('sender@example.com', $cmd['FromEmailAddress']);
            return new Result(['MessageId' => 'id']);
        });

        $mailer = $this->makeMailer($handler);
        $email  = Email::new()
            ->to('user@example.com')
            ->from('sender@example.com')
            ->subject('S')
            ->htmlBody('H');
        $mailer->send($email);
    }

    public function test_to_cc_bcc_mapped_correctly(): void
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $dest = $cmd['Destination'];
            $this->assertContains('to@example.com', $dest['ToAddresses']);
            $this->assertContains('cc@example.com', $dest['CcAddresses']);
            $this->assertContains('bcc@example.com', $dest['BccAddresses']);
            return new Result(['MessageId' => 'id']);
        });

        $mailer = $this->makeMailer($handler);
        $email  = Email::new()
            ->to('to@example.com')
            ->cc('cc@example.com')
            ->bcc('bcc@example.com')
            ->subject('S')
            ->htmlBody('H');
        $mailer->send($email);
    }

    public function test_subject_mapped_correctly(): void
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $this->assertSame('My Subject', $cmd['Content']['Simple']['Subject']['Data']);
            return new Result(['MessageId' => 'id']);
        });

        $mailer = $this->makeMailer($handler);
        $email  = Email::new()->to('u@example.com')->subject('My Subject')->htmlBody('H');
        $mailer->send($email);
    }

    public function test_html_and_text_body_mapped(): void
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $body = $cmd['Content']['Simple']['Body'];
            $this->assertSame('<p>Hello</p>', $body['Html']['Data']);
            $this->assertSame('Hello', $body['Text']['Data']);
            return new Result(['MessageId' => 'id']);
        });

        $mailer = $this->makeMailer($handler);
        $email  = Email::new()
            ->to('u@example.com')
            ->subject('S')
            ->htmlBody('<p>Hello</p>')
            ->textBody('Hello');
        $mailer->send($email);
    }

    public function test_custom_headers_mapped(): void
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $headers = $cmd['Content']['Simple']['Headers'] ?? [];
            $names   = array_column($headers, 'Name');
            $this->assertContains('X-Tenant', $names);
            return new Result(['MessageId' => 'id']);
        });

        $mailer = $this->makeMailer($handler);
        $email  = Email::new()
            ->to('u@example.com')
            ->subject('S')
            ->htmlBody('H')
            ->header('X-Tenant', 'acme');
        $mailer->send($email);
    }

    public function test_client_token_from_metadata_mapped(): void
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $this->assertSame('my-token-uuid', $cmd['ClientToken']);
            return new Result(['MessageId' => 'id']);
        });

        $mailer = $this->makeMailer($handler);
        $email  = Email::new()
            ->to('u@example.com')
            ->subject('S')
            ->htmlBody('H')
            ->withMeta('client_token', 'my-token-uuid');
        $mailer->send($email);
    }

    public function test_configuration_set_applied(): void
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $this->assertSame('my-config-set', $cmd['ConfigurationSetName']);
            return new Result(['MessageId' => 'id']);
        });

        $client = new SesV2Client([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler'     => $handler,
        ]);

        $mailer = new SesMailer($client, 'us-east-1', 'noreply@example.com', '', null, 'my-config-set');
        $email  = Email::new()->to('u@example.com')->subject('S')->htmlBody('H');
        $mailer->send($email);
    }

    public function test_attachment_uses_raw_content_type(): void
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            $this->assertArrayHasKey('Raw', $cmd['Content']);
            $this->assertArrayNotHasKey('Simple', $cmd['Content']);
            return new Result(['MessageId' => 'id']);
        });

        $mailer = $this->makeMailer($handler);
        $email  = Email::new()
            ->to('u@example.com')
            ->subject('S')
            ->htmlBody('H')
            ->attach(Attachment::fromContent('file.pdf', 'application/pdf', 'data'));
        $mailer->send($email);
    }

    public function test_too_many_requests_maps_to_rate_limit_exception(): void
    {
        $handler = new MockHandler();
        $handler->append(new AwsException(
            'Rate limit exceeded',
            new \Aws\Command('SendEmail'),
            ['code' => 'TooManyRequestsException', 'message' => 'Rate limit exceeded'],
        ));

        $mailer = $this->makeMailer($handler);
        $email  = Email::new()->to('u@example.com')->subject('S')->htmlBody('H');

        $this->expectException(RateLimitExceededException::class);
        $mailer->send($email);
    }

    public function test_throttling_maps_to_rate_limit_exception(): void
    {
        $handler = new MockHandler();
        $handler->append(new AwsException(
            'Request throttled',
            new \Aws\Command('SendEmail'),
            ['code' => 'Throttling', 'message' => 'Request throttled'],
        ));

        $mailer = $this->makeMailer($handler);
        $email  = Email::new()->to('u@example.com')->subject('S')->htmlBody('H');

        $this->expectException(RateLimitExceededException::class);
        $mailer->send($email);
    }

    public function test_message_rejected_maps_to_mail_send_exception(): void
    {
        $handler = new MockHandler();
        $handler->append(new AwsException(
            'Message rejected',
            new \Aws\Command('SendEmail'),
            ['code' => 'MessageRejected', 'message' => 'Message rejected'],
        ));

        $mailer = $this->makeMailer($handler);
        $email  = Email::new()->to('u@example.com')->subject('S')->htmlBody('H');

        $this->expectException(MailSendException::class);
        $this->expectExceptionMessage('MessageRejected');
        $mailer->send($email);
    }

    public function test_account_suspended_maps_to_mail_send_exception(): void
    {
        $handler = new MockHandler();
        $handler->append(new AwsException(
            'Account suspended',
            new \Aws\Command('SendEmail'),
            ['code' => 'AccountSuspendedException', 'message' => 'Account suspended'],
        ));

        $mailer = $this->makeMailer($handler);
        $email  = Email::new()->to('u@example.com')->subject('S')->htmlBody('H');

        $this->expectException(MailSendException::class);
        $mailer->send($email);
    }

    public function test_no_from_address_throws_mail_send_exception(): void
    {
        $handler = new MockHandler();
        $client  = new SesV2Client([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler'     => $handler,
        ]);

        $mailer = new SesMailer($client, 'us-east-1', '', '', null, null);
        $email  = Email::new()->to('u@example.com')->subject('S')->htmlBody('H');

        $this->expectException(MailSendException::class);
        $this->expectExceptionMessage('from address');
        $mailer->send($email);
    }

    public function test_validate_throws_when_no_body(): void
    {
        $handler = new MockHandler();
        $mailer  = $this->makeMailer($handler);
        $email   = Email::new()->to('u@example.com')->subject('S');

        $this->expectException(\LogicException::class);
        $mailer->send($email);
    }
}
