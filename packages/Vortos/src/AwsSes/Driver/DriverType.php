<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Driver;

enum DriverType: string
{
    /** Real AWS SES transport. Requires AWS credentials and SES_FROM_ADDRESS. */
    case Ses = 'ses';

    /** Writes email to PSR logger. No AWS calls. Use in dev/staging. */
    case Log = 'log';

    /** Silent drop. SesMailerFake is bound to MailerInterface in container. Use in tests. */
    case Null = 'null';

    public static function fromEnv(): self
    {
        return self::tryFrom($_ENV['VORTOS_MAILER_DRIVER'] ?? 'log') ?? self::Log;
    }
}
