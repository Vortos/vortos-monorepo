<?php

declare(strict_types=1);

namespace Vortos\Iac\Driver\Terraform;

final class SecretRedactor
{
    private const REDACTED = '***';
    private const MIN_SECRET_LENGTH = 4;

    /** @var list<string> */
    private array $secrets = [];

    /** @param iterable<string> $secrets plaintext values to redact */
    public function __construct(iterable $secrets = [])
    {
        foreach ($secrets as $secret) {
            if (strlen($secret) >= self::MIN_SECRET_LENGTH) {
                $this->secrets[] = $secret;
            }
        }

        usort($this->secrets, static fn (string $a, string $b) => strlen($b) <=> strlen($a));
    }

    public function redact(string $input): string
    {
        foreach ($this->secrets as $secret) {
            $input = str_replace($secret, self::REDACTED, $input);
        }

        return $input;
    }
}
