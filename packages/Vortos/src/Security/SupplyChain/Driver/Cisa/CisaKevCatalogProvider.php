<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Driver\Cisa;

use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\Security\SupplyChain\Capability\SupplyChainCapabilityKey;
use Vortos\Security\SupplyChain\Driver\Process\ProcessRunnerInterface;
use Vortos\Security\SupplyChain\Model\KevCatalogUnavailableException;
use Vortos\Security\SupplyChain\Model\Vulnerability\KevCatalog;
use Vortos\Security\SupplyChain\Port\KevCatalogProviderInterface;

#[AsDriver('cisa')]
final class CisaKevCatalogProvider implements KevCatalogProviderInterface
{
    private const KEV_URL = 'https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json';
    private const ALLOWED_SCHEMES = ['https'];

    public function __construct(
        private readonly ProcessRunnerInterface $processRunner,
        private readonly int $timeoutSeconds = 60,
        private readonly ?string $expectedHash = null,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            SupplyChainCapabilityKey::KevAware->value => true,
        ]);
    }

    public function catalog(): KevCatalog
    {
        $url = self::KEV_URL;
        $this->assertSafeUrl($url);

        try {
            $output = $this->processRunner->run(
                ['curl', '-sfL', '--max-time', (string) $this->timeoutSeconds, '--max-redirs', '3', $url],
                [],
                $this->timeoutSeconds + 10,
            );
        } catch (\Throwable $e) {
            throw new KevCatalogUnavailableException('KEV catalog fetch failed: ' . $e->getMessage());
        }

        if (!$output->isSuccessful()) {
            throw new KevCatalogUnavailableException('KEV catalog fetch returned exit code ' . $output->exitCode);
        }

        $json = $output->stdout;
        $sourceHash = 'sha256:' . hash('sha256', $json);

        if ($this->expectedHash !== null && $this->expectedHash !== $sourceHash) {
            throw new KevCatalogUnavailableException(sprintf(
                'KEV catalog content hash mismatch: expected %s, got %s.',
                $this->expectedHash,
                $sourceHash,
            ));
        }

        return self::parseCatalog($json, $sourceHash);
    }

    public static function parseCatalog(string $json, string $sourceHash): KevCatalog
    {
        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new KevCatalogUnavailableException('KEV catalog JSON parse failed: ' . $e->getMessage());
        }

        if (!is_array($data) || !isset($data['vulnerabilities'])) {
            throw new KevCatalogUnavailableException('KEV catalog missing "vulnerabilities" key.');
        }

        $ids = [];
        foreach ($data['vulnerabilities'] as $entry) {
            if (is_array($entry) && isset($entry['cveID']) && is_string($entry['cveID'])) {
                $ids[] = $entry['cveID'];
            }
        }

        return KevCatalog::fromList($ids, $sourceHash, new \DateTimeImmutable());
    }

    private function assertSafeUrl(string $url): void
    {
        $parsed = parse_url($url);

        /** @var array{scheme?: string, host?: string} $parsed */
        $scheme = strtolower($parsed['scheme'] ?? '');
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new KevCatalogUnavailableException('KEV catalog URL scheme not in allowlist.');
        }

        $host = $parsed['host'] ?? '';
        if ($this->isPrivateOrMetadataHost($host)) {
            throw new KevCatalogUnavailableException('KEV catalog URL points to a private/metadata IP (SSRF blocked).');
        }
    }

    private function isPrivateOrMetadataHost(string $host): bool
    {
        $ip = gethostbyname($host);
        if ($ip === $host) {
            return false;
        }

        return $this->isPrivateIp($ip);
    }

    private function isPrivateIp(string $ip): bool
    {
        return filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
