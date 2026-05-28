<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Integration;

use Aws\SesV2\SesV2Client;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\AwsSes\Bounce\AutoSuppressionBounceHandler;
use Vortos\AwsSes\Bounce\AutoSuppressionComplaintHandler;
use Vortos\AwsSes\Bounce\BounceHandlerRunner;
use Vortos\AwsSes\Bounce\ComplaintHandlerRunner;
use Vortos\AwsSes\Driver\Ses\SesClientFactory;
use Vortos\AwsSes\Driver\Ses\SesMailer;
use Vortos\AwsSes\Outbox\EmailOutboxRelay;
use Vortos\AwsSes\Outbox\EmailOutboxWriter;
use Vortos\AwsSes\Suppression\DbalSuppressionList;
use Vortos\AwsSes\Suppression\SuppressionReason;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\EmailAddress;
use Vortos\AwsSes\Webhook\BounceNotification;
use Vortos\AwsSes\Webhook\BounceType;
use Vortos\AwsSes\Webhook\ComplaintNotification;

/**
 * Full integration test against a real LocalStack SES endpoint.
 *
 * Run via:
 *   docker compose up -d localstack
 *   docker compose exec backend php vendor/bin/phpunit --group=integration
 *
 * Tests are skipped automatically when LocalStack is unreachable.
 */
#[Group('integration')]
final class SesLocalStackIntegrationTest extends TestCase
{
    private const ENDPOINT       = 'http://localstack:4566';
    private const REGION         = 'us-east-1';
    private const FROM           = 'sender@vortos-test.com';
    private const TO             = 'recipient@vortos-test.com';
    private const SUPPRESSION_TABLE = 'aws_ses_suppression_list';
    private const OUTBOX_TABLE      = 'aws_ses_outbox';

    private SesV2Client $client;
    private SesMailer $mailer;
    private Connection $db;

    protected function setUp(): void
    {
        $this->checkLocalStackReachable();
        $this->client = $this->buildClient();
        $this->mailer = $this->buildMailer($this->client);
        $this->db     = $this->buildSqliteConnection();
        $this->createTables();
    }

    private function checkLocalStackReachable(): void
    {
        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
        $res = @file_get_contents(self::ENDPOINT . '/_localstack/health', false, $ctx);

        if ($res === false) {
            $this->markTestSkipped('LocalStack is not reachable at ' . self::ENDPOINT);
        }

        $health = json_decode($res, true);
        $sesUp  = ($health['services']['ses'] ?? '') === 'running' ||
                  ($health['services']['sesv2'] ?? '') === 'running' ||
                  ($health['features']['persistence'] ?? null) !== null;

        if (!$sesUp) {
            $this->markTestSkipped('LocalStack SES service is not running.');
        }
    }

    private function buildClient(): SesV2Client
    {
        return SesClientFactory::create(
            region:          self::REGION,
            endpointOverride: self::ENDPOINT,
            httpTimeout:     5.0,
            maxRetries:      0,
        );
    }

    private function buildMailer(SesV2Client $client): SesMailer
    {
        return new SesMailer(
            client:             $client,
            region:             self::REGION,
            defaultFromAddress: self::FROM,
            defaultFromName:    'Vortos Test',
            defaultReplyTo:     null,
            configurationSet:   null,
        );
    }

    private function buildSqliteConnection(): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
    }

    private function createTables(): void
    {
        $this->db->executeStatement('
            CREATE TABLE ' . self::SUPPRESSION_TABLE . ' (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                email_address   TEXT NOT NULL UNIQUE,
                reason          TEXT NOT NULL,
                suppressed_at   TEXT NOT NULL,
                created_at      TEXT NOT NULL
            )
        ');

        $this->db->executeStatement('
            CREATE TABLE ' . self::OUTBOX_TABLE . ' (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                domain_event_id TEXT UNIQUE,
                status          TEXT NOT NULL DEFAULT \'pending\',
                attempt_count   INTEGER NOT NULL DEFAULT 0,
                payload         TEXT NOT NULL,
                message_id      TEXT,
                last_error      TEXT,
                next_attempt_at TEXT,
                created_at      TEXT NOT NULL,
                sent_at         TEXT
            )
        ');
    }

    private function verifyEmailIdentity(string $email): void
    {
        // LocalStack auto-accepts all verifications — just create the identity
        try {
            $this->client->createEmailIdentity(['EmailIdentity' => $email]);
        } catch (\Throwable) {
            // Already exists — fine
        }
    }

    // ── Send ────────────────────────────────────────────────────────────────

    public function test_send_returns_message_id(): void
    {
        $this->verifyEmailIdentity(self::FROM);

        $email = Email::new()
            ->from(self::FROM)
            ->to(self::TO)
            ->subject('LocalStack Integration Test')
            ->htmlBody('<p>Hello from Vortos SES integration test.</p>')
            ->textBody('Hello from Vortos SES integration test.');

        $sent = $this->mailer->send($email);

        $this->assertNotEmpty($sent->messageId());
        $this->assertSame(self::REGION, $sent->region());
        $this->assertSame('ses', $sent->driver());
    }

    public function test_send_multiple_recipients(): void
    {
        $this->verifyEmailIdentity(self::FROM);

        $email = Email::new()
            ->from(self::FROM)
            ->to(self::TO)
            ->to('second@vortos-test.com')
            ->subject('Multi-recipient test')
            ->htmlBody('<p>Two recipients.</p>');

        $sent = $this->mailer->send($email);

        $this->assertNotEmpty($sent->messageId());
        $this->assertSame(2, $sent->recipientCount());
    }

    public function test_send_with_attachment(): void
    {
        $this->verifyEmailIdentity(self::FROM);

        $email = Email::new()
            ->from(self::FROM)
            ->to(self::TO)
            ->subject('Attachment test')
            ->htmlBody('<p>See attached.</p>')
            ->attach(\Vortos\AwsSes\ValueObject\Attachment::fromContent(
                'Test attachment content',
                'test.txt',
                'text/plain',
            ));

        $sent = $this->mailer->send($email);
        $this->assertNotEmpty($sent->messageId());
    }

    // ── Suppression List ────────────────────────────────────────────────────

    public function test_suppression_list_suppress_and_check(): void
    {
        $list    = new DbalSuppressionList($this->db, self::SUPPRESSION_TABLE);
        $address = new EmailAddress('suppressed@example.com');

        $this->assertFalse($list->isSuppressed($address));

        $list->suppress($address, SuppressionReason::Bounce);

        $this->assertTrue($list->isSuppressed($address));
    }

    public function test_suppression_list_unsuppress(): void
    {
        $list    = new DbalSuppressionList($this->db, self::SUPPRESSION_TABLE);
        $address = new EmailAddress('removeme@example.com');

        $list->suppress($address, SuppressionReason::Complaint);
        $this->assertTrue($list->isSuppressed($address));

        $list->unsuppress($address);
        $this->assertFalse($list->isSuppressed($address));
    }

    public function test_suppression_list_upsert_updates_reason(): void
    {
        $list    = new DbalSuppressionList($this->db, self::SUPPRESSION_TABLE);
        $address = new EmailAddress('upsert@example.com');

        $list->suppress($address, SuppressionReason::Bounce);
        $list->suppress($address, SuppressionReason::Complaint); // upsert

        $entries = $list->list();
        $match   = array_filter($entries, fn($e) => $e['email_address'] === 'upsert@example.com');

        $this->assertCount(1, $match);
        $this->assertSame('complaint', array_values($match)[0]['reason']);
    }

    public function test_suppression_list_pagination(): void
    {
        $list = new DbalSuppressionList($this->db, self::SUPPRESSION_TABLE);

        for ($i = 1; $i <= 5; $i++) {
            $list->suppress(new EmailAddress("addr{$i}@example.com"), SuppressionReason::Bounce);
        }

        $page1 = $list->list(limit: 3, offset: 0);
        $page2 = $list->list(limit: 3, offset: 3);

        $this->assertCount(3, $page1);
        $this->assertCount(2, $page2);
    }

    // ── Outbox ──────────────────────────────────────────────────────────────

    public function test_outbox_writer_and_relay_send_email(): void
    {
        $this->verifyEmailIdentity(self::FROM);

        $writer = new EmailOutboxWriter($this->db, self::OUTBOX_TABLE);
        $relay  = new EmailOutboxRelay(
            connection:          $this->db,
            mailer:              $this->mailer,
            logger:              new NullLogger(),
            tableName:           self::OUTBOX_TABLE,
            batchSize:           10,
            maxDeliveryAttempts: 3,
            backoffBaseSeconds:  1,
            backoffCapSeconds:   60,
        );

        $email = Email::new()
            ->from(self::FROM)
            ->to(self::TO)
            ->subject('Outbox relay integration test')
            ->htmlBody('<p>Sent via outbox relay.</p>');

        $this->db->beginTransaction();
        $writer->queue($email);
        $this->db->commit();

        $sent = $relay->relay();
        $this->assertSame(1, $sent);

        // Row should be marked sent
        $row = $this->db->fetchAssociative(
            'SELECT status FROM ' . self::OUTBOX_TABLE . ' LIMIT 1'
        );
        $this->assertSame('sent', $row['status']);
    }

    public function test_outbox_idempotency_via_domain_event_id(): void
    {
        $this->verifyEmailIdentity(self::FROM);

        $writer = new EmailOutboxWriter($this->db, self::OUTBOX_TABLE);

        $email = Email::new()
            ->from(self::FROM)
            ->to(self::TO)
            ->subject('Idempotency test')
            ->htmlBody('<p>Deduped.</p>');

        $this->db->beginTransaction();
        $writer->queue($email, domainEventId: 'evt-unique-001');
        $this->db->commit();

        // Second queue with same domain event id should be silently ignored
        $this->db->beginTransaction();
        $writer->queue($email, domainEventId: 'evt-unique-001');
        $this->db->commit();

        $count = (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . self::OUTBOX_TABLE);
        $this->assertSame(1, $count);
    }

    // ── Bounce & Complaint Handling ─────────────────────────────────────────

    public function test_bounce_handler_runner_suppresses_hard_bounce(): void
    {
        $list    = new DbalSuppressionList($this->db, self::SUPPRESSION_TABLE);
        $runner  = new BounceHandlerRunner(
            [new AutoSuppressionBounceHandler($list, new NullLogger())],
            new NullLogger(),
        );

        $notification = new BounceNotification(
            recipient:      new EmailAddress('bounced@example.com'),
            bounceType:     BounceType::Permanent,
            bounceSubType:  'General',
            diagnosticCode: '550 User unknown',
            timestamp:      new DateTimeImmutable(),
        );

        $runner->run($notification);

        $this->assertTrue($list->isSuppressed(new EmailAddress('bounced@example.com')));
    }

    public function test_bounce_handler_runner_does_not_suppress_soft_bounce(): void
    {
        $list   = new DbalSuppressionList($this->db, self::SUPPRESSION_TABLE);
        $runner = new BounceHandlerRunner(
            [new AutoSuppressionBounceHandler($list, new NullLogger())],
            new NullLogger(),
        );

        $notification = new BounceNotification(
            recipient:      new EmailAddress('transient@example.com'),
            bounceType:     BounceType::Transient,
            bounceSubType:  'MailboxFull',
            diagnosticCode: '452 Mailbox full',
            timestamp:      new DateTimeImmutable(),
        );

        $runner->run($notification);

        $this->assertFalse($list->isSuppressed(new EmailAddress('transient@example.com')));
    }

    public function test_complaint_handler_runner_suppresses_complainant(): void
    {
        $list   = new DbalSuppressionList($this->db, self::SUPPRESSION_TABLE);
        $runner = new ComplaintHandlerRunner(
            [new AutoSuppressionComplaintHandler($list, new NullLogger())],
            new NullLogger(),
        );

        $notification = new ComplaintNotification(
            recipient:             new EmailAddress('complainer@example.com'),
            complaintFeedbackType: 'abuse',
            timestamp:             new DateTimeImmutable(),
        );

        $runner->run($notification);

        $this->assertTrue($list->isSuppressed(new EmailAddress('complainer@example.com')));
    }

    // ── Quota ───────────────────────────────────────────────────────────────

    public function test_get_send_quota_returns_positive_limits(): void
    {
        $result = $this->client->getSendQuota();

        $this->assertArrayHasKey('Max24HourSend',   $result->toArray());
        $this->assertArrayHasKey('SentLast24Hours', $result->toArray());
        $this->assertArrayHasKey('MaxSendRate',     $result->toArray());
        $this->assertGreaterThan(0, (float) $result['Max24HourSend']);
    }
}
