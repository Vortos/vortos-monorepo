<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Outbox;

use Doctrine\DBAL\Connection;
use Vortos\AwsSes\Contract\MailerInterface;
use Vortos\AwsSes\Contract\StandaloneMailerInterface;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

final class StandaloneMailer implements StandaloneMailerInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MailerInterface $transactionalMailer,
    ) {}

    public function send(Email $email): SentEmail
    {
        if ($this->connection->isTransactionActive()) {
            return $this->transactionalMailer->send($email);
        }

        return $this->connection->transactional(fn(): SentEmail => $this->transactionalMailer->send($email));
    }
}
