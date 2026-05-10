<?php

declare(strict_types=1);

namespace Vortos\Security\Masking;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Vortos\Security\Contract\MaskingStrategyInterface;

/**
 * Monolog processor that masks sensitive values in log record context.
 *
 * Scans the 'context' and 'extra' arrays recursively for keys that match
 * known sensitive field names. When found, replaces the value with the
 * masked version produced by the configured strategy.
 *
 * Sensitive field names are registered via addSensitiveKey() — called by
 * the DI container after scanning #[Sensitive] attributes at compile time.
 *
 * Default sensitive keys (always masked, regardless of attribute scanning):
 *   password, passwd, secret, token, api_key, apikey, authorization,
 *   credit_card, card_number, cvv, ssn, social_security_number
 */
final class DataMaskingProcessor implements ProcessorInterface
{
    /** @var array<string, MaskingStrategyInterface> key → strategy */
    private array $sensitiveKeys;

    public function __construct(
        private readonly MaskingStrategyInterface $strategy,
    ) {
        $this->sensitiveKeys = $this->defaultSensitiveKeys();
    }

    public function addSensitiveKey(string $key, ?MaskingStrategyInterface $strategy = null): void
    {
        $this->sensitiveKeys[strtolower($key)] = $strategy ?? $this->strategy;
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->maskArray($record->context),
            extra:   $this->maskArray($record->extra),
        );
    }

    private function maskArray(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            if (isset($this->sensitiveKeys[$lowerKey]) && is_string($value)) {
                $result[$key] = $this->sensitiveKeys[$lowerKey]->mask($value);
            } elseif (is_array($value)) {
                $result[$key] = $this->maskArray($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private function defaultSensitiveKeys(): array
    {
        $defaults = [
            'password', 'passwd', 'pass', 'secret', 'token', 'access_token',
            'refresh_token', 'api_key', 'apikey', 'api_secret', 'authorization',
            'credit_card', 'card_number', 'cvv', 'cvc', 'ssn',
            'social_security_number', 'private_key', 'jwt',
        ];

        $result = [];
        foreach ($defaults as $key) {
            $result[$key] = $this->strategy;
        }
        return $result;
    }
}
