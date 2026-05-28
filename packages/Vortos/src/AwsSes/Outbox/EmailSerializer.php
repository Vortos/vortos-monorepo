<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Outbox;

use Vortos\AwsSes\ValueObject\Attachment;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\EmailAddress;

/**
 * Serializes and deserializes Email value objects to/from JSON-safe arrays.
 *
 * Used exclusively by the outbox writer and relay worker to persist email
 * intent to the database and reconstruct it for delivery.
 *
 * Attachment content is stored as-is (already base64-encoded by the VO).
 */
final class EmailSerializer
{
    public static function toArray(Email $email): array
    {
        return [
            'to'      => self::serializeAddresses($email->getTo()),
            'cc'      => self::serializeAddresses($email->getCc()),
            'bcc'     => self::serializeAddresses($email->getBcc()),
            'from'    => self::serializeAddress($email->getFrom()),
            'replyTo' => self::serializeAddress($email->getReplyTo()),
            'subject'  => $email->getSubject(),
            'htmlBody' => $email->getHtmlBody(),
            'textBody' => $email->getTextBody(),
            'headers'  => $email->getHeaders(),
            'attachments' => array_map(
                fn(Attachment $a) => $a->toArray(),
                $email->getAttachments(),
            ),
            'metadata' => $email->getMetadata(),
        ];
    }

    public static function fromArray(array $data): Email
    {
        $email = Email::new();

        foreach ($data['to'] ?? [] as $addr) {
            $email->to(self::deserializeAddress($addr));
        }
        foreach ($data['cc'] ?? [] as $addr) {
            $email->cc(self::deserializeAddress($addr));
        }
        foreach ($data['bcc'] ?? [] as $addr) {
            $email->bcc(self::deserializeAddress($addr));
        }

        if ($data['from'] !== null) {
            $email->from(self::deserializeAddress($data['from']));
        }
        if ($data['replyTo'] !== null) {
            $email->replyTo(self::deserializeAddress($data['replyTo']));
        }

        $email->subject($data['subject'] ?? '');

        if ($data['htmlBody'] !== null) {
            $email->htmlBody($data['htmlBody']);
        }
        if ($data['textBody'] !== null) {
            $email->textBody($data['textBody']);
        }

        foreach ($data['headers'] ?? [] as $name => $value) {
            $email->header($name, $value);
        }

        foreach ($data['attachments'] ?? [] as $att) {
            $email->attach(Attachment::fromEncoded(
                (string) $att['filename'],
                (string) $att['mime_type'],
                (string) $att['content'],
                (bool)   ($att['inline'] ?? false),
                isset($att['content_id']) ? (string) $att['content_id'] : null,
            ));
        }

        // Restore metadata — withMeta() returns a clone, so we chain outside the fluent setters
        $result = $email;
        foreach ($data['metadata'] ?? [] as $key => $value) {
            $result = $result->withMeta($key, $value);
        }

        return $result;
    }

    private static function serializeAddresses(array $addresses): array
    {
        return array_map([self::class, 'serializeAddress'], $addresses);
    }

    private static function serializeAddress(?EmailAddress $address): ?array
    {
        if ($address === null) {
            return null;
        }
        return ['address' => $address->address(), 'name' => $address->name()];
    }

    private static function deserializeAddress(array $data): EmailAddress
    {
        return new EmailAddress((string) $data['address'], $data['name'] ?? null);
    }
}
