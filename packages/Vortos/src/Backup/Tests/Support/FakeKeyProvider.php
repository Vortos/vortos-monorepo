<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Support;

use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\Secrets\Key\DataKey;
use Vortos\Secrets\Key\KeyProviderInterface;
use Vortos\Secrets\Key\WrappedKey;

/**
 * @internal test support — wraps by prepending a known prefix, unwraps by stripping it.
 */
final class FakeKeyProvider implements KeyProviderInterface
{
    private const PREFIX = 'FAKEWRAP:';

    private bool $unwrapEnabled;

    public function __construct(bool $unwrapEnabled = true)
    {
        $this->unwrapEnabled = $unwrapEnabled;
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create(['wrap' => true, 'unwrap' => true]);
    }

    public function providerName(): string
    {
        return 'fake-age';
    }

    public function wrap(DataKey $dataKey): WrappedKey
    {
        return new WrappedKey(self::PREFIX . $dataKey->revealForEncryption(), 'fake-recipient');
    }

    public function unwrap(WrappedKey $wrappedKey): DataKey
    {
        if (!$this->unwrapEnabled) {
            throw new \Vortos\Secrets\Exception\KeyUnavailableException('Unwrap disabled in test.');
        }

        $raw = $wrappedKey->ciphertext;
        if (!str_starts_with($raw, self::PREFIX)) {
            throw new \Vortos\Secrets\Exception\DecryptionFailedException('Bad fake wrap prefix.');
        }

        return DataKey::fromRaw(substr($raw, strlen(self::PREFIX)));
    }

    public function disableUnwrap(): void
    {
        $this->unwrapEnabled = false;
    }

    public function enableUnwrap(): void
    {
        $this->unwrapEnabled = true;
    }
}
