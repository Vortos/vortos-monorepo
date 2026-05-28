<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Driver\Ses;

use Aws\Exception\AwsException;
use Aws\SesV2\SesV2Client;
use DateTimeImmutable;
use Vortos\AwsSes\Contract\MailerInterface;
use Vortos\AwsSes\Exception\MailSendException;
use Vortos\AwsSes\Exception\RateLimitExceededException;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\EmailAddress;
use Vortos\AwsSes\ValueObject\SentEmail;

/**
 * AWS SES V2 transport.
 *
 * Builds a SendEmail request from the Email value object and calls the SES API.
 * Deduplication (ClientToken), suppression check, and rate limiting are handled
 * upstream by the middleware stack — this class only concerns itself with the
 * wire format and error mapping.
 *
 * Error mapping:
 *   TooManyRequestsException  → RateLimitExceededException
 *   MessageRejected           → MailSendException
 *   SendingPausedException    → MailSendException
 *   AccountSuspendedException → MailSendException
 *   All others                → MailSendException
 */
final class SesMailer implements MailerInterface
{
    public function __construct(
        private readonly SesV2Client $client,
        private readonly string $region,
        private readonly string $defaultFromAddress,
        private readonly string $defaultFromName,
        private readonly ?string $defaultReplyTo,
        private readonly ?string $configurationSet,
    ) {}

    public function send(Email $email): SentEmail
    {
        $email->validate();

        $request = $this->buildRequest($email);

        try {
            $result = $this->client->sendEmail($request);
        } catch (AwsException $e) {
            $this->mapException($e);
        }

        return new SentEmail(
            messageId: $result['MessageId'],
            sentAt: new DateTimeImmutable(),
            recipientCount: count($email->getAllRecipients()),
            driver: 'ses',
            region: $this->region,
        );
    }

    private function buildRequest(Email $email): array
    {
        $from = $email->getFrom()
            ?? ($this->defaultFromAddress !== ''
                ? new EmailAddress($this->defaultFromAddress, $this->defaultFromName ?: null)
                : throw MailSendException::noFromAddress());

        $request = [
            'FromEmailAddress' => $from->toString(),
            'Destination'      => [
                'ToAddresses'  => array_map(fn(EmailAddress $a) => $a->toString(), $email->getTo()),
                'CcAddresses'  => array_map(fn(EmailAddress $a) => $a->toString(), $email->getCc()),
                'BccAddresses' => array_map(fn(EmailAddress $a) => $a->toString(), $email->getBcc()),
            ],
            'Content' => $this->buildContent($email),
        ];

        $replyTo = $email->getReplyTo()
            ?? ($this->defaultReplyTo !== null ? new EmailAddress($this->defaultReplyTo) : null);

        if ($replyTo !== null) {
            $request['ReplyToAddresses'] = [$replyTo->toString()];
        }

        if ($this->configurationSet !== null) {
            $request['ConfigurationSetName'] = $this->configurationSet;
        }

        $clientToken = $email->getMeta('client_token');
        if ($clientToken !== null) {
            $request['ClientToken'] = $clientToken;
        }

        return $request;
    }

    private function buildContent(Email $email): array
    {
        if (count($email->getAttachments()) > 0) {
            return $this->buildRawContent($email);
        }

        $body = [];

        if ($email->getHtmlBody() !== null) {
            $body['Html'] = ['Data' => $email->getHtmlBody(), 'Charset' => 'UTF-8'];
        }

        if ($email->getTextBody() !== null) {
            $body['Text'] = ['Data' => $email->getTextBody(), 'Charset' => 'UTF-8'];
        }

        $simple = [
            'Subject' => ['Data' => $email->getSubject(), 'Charset' => 'UTF-8'],
            'Body'    => $body,
        ];

        $headers = $email->getHeaders();
        if ($headers !== []) {
            $simple['Headers'] = array_map(
                fn(string $name, string $value) => ['Name' => $name, 'Value' => $value],
                array_keys($headers),
                array_values($headers),
            );
        }

        return ['Simple' => $simple];
    }

    private function buildRawContent(Email $email): array
    {
        // Build a minimal MIME message with attachments
        $boundary   = 'ses-boundary-' . bin2hex(random_bytes(8));
        $altBoundary = 'ses-alt-' . bin2hex(random_bytes(8));

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Subject: {$email->getSubject()}\r\n";

        foreach ($email->getHeaders() as $name => $value) {
            $headers .= "{$name}: {$value}\r\n";
        }

        $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";

        if ($email->getTextBody() !== null) {
            $body .= "--{$altBoundary}\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
            $body .= $email->getTextBody() . "\r\n";
        }

        if ($email->getHtmlBody() !== null) {
            $body .= "--{$altBoundary}\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
            $body .= $email->getHtmlBody() . "\r\n";
        }

        $body .= "--{$altBoundary}--\r\n";

        foreach ($email->getAttachments() as $attachment) {
            $disposition = $attachment->isInline() ? 'inline' : 'attachment';
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: {$attachment->mimeType()}; name=\"{$attachment->filename()}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-Disposition: {$disposition}; filename=\"{$attachment->filename()}\"\r\n";

            if ($attachment->isInline() && $attachment->contentId() !== null) {
                $body .= "Content-ID: <{$attachment->contentId()}>\r\n";
            }

            $body .= "\r\n" . chunk_split($attachment->content(), 76, "\r\n");
        }

        $body .= "--{$boundary}--\r\n";

        return ['Raw' => ['Data' => $headers . "\r\n" . $body]];
    }

    private function mapException(AwsException $e): never
    {
        $code = $e->getAwsErrorCode() ?? '';

        if ($code === 'TooManyRequestsException' || $code === 'Throttling') {
            throw new RateLimitExceededException(
                sprintf('AWS SES rate limit: %s', $e->getAwsErrorMessage() ?? $e->getMessage()),
                0,
                $e,
            );
        }

        throw MailSendException::fromSesError($code, $e->getAwsErrorMessage() ?? $e->getMessage(), $e);
    }
}
