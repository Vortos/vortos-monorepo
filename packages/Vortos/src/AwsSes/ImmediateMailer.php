<?php

declare(strict_types=1);

namespace Vortos\AwsSes;

use Vortos\AwsSes\Contract\ImmediateMailerInterface;
use Vortos\AwsSes\Contract\MailerInterface;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

final class ImmediateMailer implements ImmediateMailerInterface
{
    public function __construct(private readonly MailerInterface $inner) {}

    public function send(Email $email): SentEmail
    {
        return $this->inner->send($email);
    }
}
